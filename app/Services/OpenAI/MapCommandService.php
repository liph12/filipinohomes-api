<?php

namespace App\Services\OpenAI;

use App\Models\Listing;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "where is that?" — the listings just shown, on a map, with the route from the buyer.
 *
 * No model: the listing is the one named by id ("Where is this? listing #123") or, for a typed
 * "where is that / how do I get there", the listings most recently shown in this thread. The pins
 * are the properties' own map coordinates. The page draws the map and asks the browser for the
 * buyer's location to route from; "how far is it from Ayala Center" routes from that landmark
 * instead (LandmarkLocator). Listings without a pin are named, never placed.
 */
class MapCommandService extends CacheService
{
    use InsightStream;

    public function __construct(private readonly DataLayerService $data, private readonly LandmarkLocator $landmarks)
    {
    }

    public function replyStream(array $thread, Request $req): StreamedResponse
    {
        $last = collect($thread)->where('role', 'user')->last();
        $question = is_array($last) ? trim((string) ($last['content'] ?? '')) : '';

        $ids = [];
        if (preg_match_all('/\blisting\s*#\s*(\d+)/i', $question, $mm) && $mm[1] !== []) {
            $ids = array_values(array_unique(array_map('intval', $mm[1])));
        } else {
            $ids = $this->idsFromThread($req);
        }
        if ($ids === []) {
            AsaQuestionLog::outcome($req, 'no_data');

            return $this->streamInsight('locate', 'map', ['empty' => true], "Which property do you mean? Ask me for listings first — for example **\"house and lot in Cebu City under ₱5M\"** — then say **\"where is that?\"** and I'll put it on the map with the route from where you are.", [], $req);
        }

        $listings = Listing::publiclyListed()
            ->with(['property', 'category', 'agent'])
            ->whereIn('id', array_slice($ids, 0, 10))
            ->get()
            ->keyBy('id');
        $pins = [];
        $unpinned = [];
        foreach ($ids as $id) {
            $l = $listings->get($id);
            if ($l === null) {
                continue;
            }
            $geo = $l->property?->geo_coordinates;
            $geo = is_array($geo) ? $geo : (is_string($geo) ? (json_decode($geo, true) ?: []) : []);
            $lat = isset($geo['lat']) ? (float) $geo['lat'] : 0.0;
            $lng = isset($geo['lng']) ? (float) $geo['lng'] : 0.0;
            $name = trim((string) ($l->property->name ?? $l->name)) ?: $l->name;
            if ($lat === 0.0 || $lng === 0.0 || abs($lat) > 90 || abs($lng) > 180) {
                $unpinned[] = $name;
                continue;
            }
            $pins[] = [
                'id' => (int) $l->id,
                'name' => $name,
                'slug' => $l->slug,
                'price' => (float) $l->price,
                'category' => (string) ($l->category->name ?? ''),
                'address' => (string) ($l->property->address ?? ''),
                'photo' => $l->featured_photo,
                'lat' => $lat,
                'lng' => $lng,
                'agent' => trim(($l->agent->first_name ?? '').' '.($l->agent->last_name ?? '')) ?: null,
            ];
        }
        if ($pins === []) {
            AsaQuestionLog::outcome($req, 'no_data');
            $names = $unpinned !== [] ? '**'.implode('**, **', array_slice($unpinned, 0, 3)).'**' : 'that listing';

            return $this->streamInsight('locate', 'map', ['empty' => true], "I don't have a map pin for {$names} yet — the agent hasn't placed it on the map. The address on the card is the best I have; ask the agent and they can pin it.", [], $req);
        }

        // "How far is it from Ayala Center?" — route from that place instead of the buyer's location.
        $origin = null;
        if (preg_match('/\bfrom\s+(?!me\b|here\b|my\s)(.+?)\s*[?.!]*$/iu', $question, $m)) {
            $cityHint = (string) preg_replace('/^.*?,\s*/', '', $pins[0]['address']);
            $point = $this->landmarks->locate(trim($m[1]), $cityHint);
            if ($point !== null) {
                $origin = ['label' => (string) $point['label'], 'lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
            }
        }

        $focus = $pins[0];
        $card = $this->data->cardFor($focus['id']);
        $spot = trim((string) preg_replace('/,\s*(Philippines|\d{4})\s*$/i', '', $focus['address']));
        if (count($pins) === 1) {
            $text = sprintf('**%s** is at **%s**. %s', $focus['name'], $spot ?: 'the pinned spot', $origin ? sprintf('The map shows the way there from **%s**.', $origin['label']) : 'Allow location access and the map will draw the way there from where you are.');
        } else {
            $text = sprintf('Here are the **%d** on the map — **#1 is %s**, the one I suggested. Tap a pin to see the way to it%s.', count($pins), $focus['name'], $origin ? sprintf(' from **%s**', $origin['label']) : ' from where you are');
        }
        if ($unpinned !== []) {
            $text .= sprintf(' (%s %s no map pin yet.)', implode(', ', array_slice($unpinned, 0, 2)), count($unpinned) === 1 ? 'has' : 'have');
        }
        $barangay = trim((string) explode(',', $focus['address'])[0]);
        $followUps = array_values(array_filter([
            ['label' => 'How far from the city centre?', 'prompt' => sprintf('How far is listing #%d from %s city hall?', $focus['id'], self::cityOf($focus['address']) ?: 'the')],
            ['label' => 'Is it a good deal?', 'prompt' => "Is this a good deal? listing #{$focus['id']}"],
            $barangay !== '' ? ['label' => "What's for sale near it", 'prompt' => sprintf('Show me %s for sale near %s', strtolower((string) ($card['propertyType'] ?? 'properties')).'s', $barangay)] : null,
            ['label' => 'Total cost to buy', 'prompt' => "What will it really cost to buy listing #{$focus['id']}?"],
        ]));
        AsaQuestionLog::outcome($req, 'answered', $spot ?: null);

        return $this->streamInsight('locate', 'map', [
            'pins' => $pins,
            'focus_id' => $focus['id'],
            'listing' => $card,
            'origin' => $origin,
            'unpinned' => $unpinned,
        ], $text, $followUps, $req);
    }

    /**
     * The listings most recently shown in this thread: a search's picks, or the one a deal, cost or
     * investment panel was about, or the cards under an affordability or valuation panel.
     *
     * @return array<int, int>
     */
    private function idsFromThread(Request $req): array
    {
        try {
            $messages = $this->getDailyMessages($req);
        } catch (\Throwable) {
            return [];
        }
        foreach (array_reverse($messages) as $m) {
            $meta = $m['metaData'] ?? null;
            if (! is_array($meta)) {
                continue;
            }
            if (isset($meta['listing']) && is_array($meta['listing'])) {
                $ids = [];
                if (! empty($meta['listing']['suggested']['id'])) {
                    $ids[] = (int) $meta['listing']['suggested']['id'];
                }
                foreach ($meta['listing']['others'] ?? [] as $o) {
                    if (! empty($o['id'])) {
                        $ids[] = (int) $o['id'];
                    }
                }
                if ($ids !== []) {
                    return array_values(array_unique($ids));
                }
            }
            foreach (['deal', 'cost', 'invest', 'map'] as $k) {
                $id = (int) ($meta[$k]['listing_id'] ?? $meta[$k]['focus_id'] ?? 0);
                if ($id > 0) {
                    return [$id];
                }
            }
            foreach (['afford' => 'listings', 'worth' => 'comparables'] as $k => $field) {
                $rows = $meta[$k][$field] ?? null;
                if (is_array($rows) && $rows !== []) {
                    return array_values(array_unique(array_map(fn ($r) => (int) ($r['id'] ?? 0), $rows)));
                }
            }
        }

        return [];
    }

    /** "…, Lahug, Cebu City, Cebu, 6000, Philippines" → "Cebu City". */
    private static function cityOf(string $address): ?string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== '' && ! preg_match('/^\d{4}$/', $p) && strcasecmp($p, 'Philippines') !== 0));
        foreach ($parts as $p) {
            if (preg_match('/\bcity\b/i', $p)) {
                return $p;
            }
        }

        return count($parts) >= 2 ? $parts[count($parts) - 2] : null;
    }
}
