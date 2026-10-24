<?php

namespace App\Services\OpenAI;

use App\Services\Listing\PriceGuideService;
use OpenAI;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "how much is it there?" answer — the Price Guide, in chat.
 *
 * "Magkano ang studio sa Lahug?" → the model only EXTRACTS the where/what (city, barangay, sale or rent,
 * unit size, type); the numbers come from PriceGuideService (our own live listings, median and 25th–75th
 * percentile), and the reply is written here, deterministically, in the shape the team asked for:
 *
 *   Lahug, Cebu City
 *   For Sale
 *    Studio: ₱3.2M (₱2M – ₱4.3M)
 *    2 Bedroom: ₱9.2M (₱5.8M – ₱13.4M)
 *   For Rent
 *    Studio: ₱18K/month (₱15K – ₱25K)
 *
 * so the model can never invent a price. Streams the same SSE shape as the other replies
 * (mode event, text chunks, [DONE]) and is cached in the thread like them.
 */
class PriceCommandService extends CacheService
{
    private $client;

    public function __construct(private readonly PriceGuideService $guide)
    {
        $this->client = OpenAI::client(config('services.openai.key'));
    }

    use InsightStream;

    public function replyStream(array $thread, $req): StreamedResponse
    {
        $asked = $this->extract($thread);
        $place = $this->resolvePlace($asked);
        $insight = $place === null ? null : $this->insight($place, $asked);

        if ($insight === null) {
            $text = $place === null ? $this->notFoundText($asked) : $this->answerText($place, $asked);

            return $this->streamInsight('price', 'price', ['empty' => true], $text, [], $req);
        }

        $spot = $place['barangay'] ?: $place['city'];
        $text = sprintf('Typical asking prices in **%s**, from %d live listings on Filipino Homes.', $insight['area']['label'], $insight['totals']['listings']);
        $followUps = [
            ['label' => "Listings in {$spot}", 'prompt' => "Show me properties for sale in {$spot}"],
            ['label' => "About {$spot}", 'prompt' => "Tell me about {$spot} as an area"],
            ['label' => 'Agents who work here', 'prompt' => "Find me the best agent for condos in {$spot}"],
        ];

        return $this->streamInsight('price', 'price', $insight, $text, $followUps, $req);
    }

    /**
     * The guide, narrowed to what was asked, as data for the page's table: per category, per type,
     * the size rows with low / typical / high. Null when nothing matches.
     *
     * @return array<string, mixed>|null
     */
    public function insight(array $place, array $asked): ?array
    {
        $guide = $this->guide->guide($place['city_id'], $place['barangay_id']);
        if ($guide === null || $guide['sections'] === []) {
            return null;
        }
        $where = $place['barangay'] ? "{$place['barangay']}, {$place['city']}" : "{$place['city']}, {$place['province']}";
        $sections = [];
        foreach ($guide['sections'] as $s) {
            if ($asked['category'] !== '' && $s['category'] !== $asked['category']) {
                continue;
            }
            $types = [];
            foreach ($s['types'] as $t) {
                if (! $this->typeMatches($t['type'], $asked['property_type'])) {
                    continue;
                }
                $sized = array_values(array_filter($t['tiers'], fn ($x) => $x['tier'] !== 'All sizes'));
                $rows = $asked['tier'] !== '' ? array_values(array_filter($sized, fn ($x) => $x['tier'] === $asked['tier'])) : ($sized !== [] ? $sized : $t['tiers']);
                if ($rows === []) {
                    continue;
                }
                $types[] = ['type' => $t['type'], 'listings' => $t['listings'], 'rows' => array_values(array_map(fn (array $x) => array_intersect_key($x, array_flip(['tier', 'listings', 'low', 'median', 'high', 'median_ppsqm'])), $rows))];
            }
            if ($types !== []) {
                $sections[] = ['category' => $s['category'], 'listings' => $s['listings'], 'types' => $types];
            }
        }
        if ($sections === []) {
            return null;
        }

        return [
            'area' => ['label' => $where, 'city' => $place['city'], 'barangay' => $place['barangay'], 'province' => $place['province']],
            'totals' => $guide['totals'],
            'asked' => $asked,
            'sections' => $sections,
        ];
    }

    /**
     * What the user is asking about, from the thread — place and optional narrowing. Never prices.
     *
     * @return array{city:string, barangay:string, category:string, tier:string, property_type:string}
     */
    public function extract(array $thread): array
    {
        $empty = ['city' => '', 'barangay' => '', 'category' => '', 'tier' => '', 'property_type' => ''];

        try {
            $response = $this->client->chat()->create([
                'model' => 'gpt-5.4-mini',
                'messages' => array_merge([[
                    'role' => 'system',
                    'content' => <<<'SYSTEM'
                    You extract WHERE and WHAT from a Philippine real-estate price question for Filipino Homes.
                    Use the MOST RECENT user message; earlier messages only if they name the place the latest one refers to.
                    Return the city and, when named, the barangay (district) — e.g. "Lahug" is a barangay of "Cebu City";
                    "Mactan" of "Lapu-Lapu City"; "BGC" / "Bonifacio Global City" of "Taguig City"; "IT Park" is in Lahug, Cebu City.
                    If only a barangay is named and you know its city, fill the city too. Do not guess a place that was not implied.
                    Expand abbreviations to full names: "CDO" → "Cagayan de Oro", "BGC" → barangay "Bonifacio Global City" in "Taguig City", "QC" → "Quezon City", "GenSan" → "General Santos City".
                    Leave fields empty ("") when not mentioned. Never output prices.
                    SYSTEM,
                ]], $thread),
                'functions' => [[
                    'name' => 'extract_price_query',
                    'description' => 'The place and optional narrowing of a "how much does it cost" question.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string', 'description' => 'City or municipality, e.g. "Cebu City", "Makati City". "" if none.'],
                            'barangay' => ['type' => 'string', 'description' => 'Barangay / district inside the city, e.g. "Lahug". "" if none.'],
                            'category' => ['type' => 'string', 'enum' => ['', 'For Sale', 'For Rent'], 'description' => 'Buying = For Sale, renting = For Rent, "" if not said.'],
                            'tier' => ['type' => 'string', 'enum' => ['', 'Studio', '1 Bedroom', '2 Bedroom', '3 Bedroom', '4+ Bedroom'], 'description' => 'Unit size if said ("2BR" = "2 Bedroom", 4 or more = "4+ Bedroom").'],
                            'property_type' => ['type' => 'string', 'enum' => ['', 'Condominium', 'House', 'Apartment', 'Lot', 'Commercial'], 'description' => 'Property type if said (condo = Condominium, house/townhouse = House, lot/land = Lot).'],
                        ],
                        'required' => ['city', 'barangay', 'category', 'tier', 'property_type'],
                    ],
                ]],
                'function_call' => ['name' => 'extract_price_query'],
            ]);

            $args = json_decode($response->choices[0]->message->functionCall->arguments ?? '{}', true) ?: [];

            return array_merge($empty, array_map(fn ($v) => is_string($v) ? trim($v) : '', array_intersect_key($args, $empty)));
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * The guide place the extracted names point at: the barangay when one is named and known (in the named
     * city when that is known too), else the city. Null when we have no guide for it.
     */
    public function resolvePlace(array $asked): ?array
    {
        $places = $this->guide->places();
        $norm = fn (string $s) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9ñ ]/u', ' ', mb_strtolower($s))));
        $cityForms = function (string $s) use ($norm): array {
            $n = $norm($s);

            return array_values(array_unique(array_filter([$n, trim(preg_replace('/\b(city|municipality)\b/', '', $n))])));
        };

        $wantB = $norm($asked['barangay'] ?? '');
        $wantC = $cityForms($asked['city'] ?? '');

        if ($wantB !== '') {
            $hits = array_values(array_filter($places, fn ($p) => $p['barangay'] !== null && (
                $norm($p['barangay']) === $wantB || $norm(preg_replace('/\(.*?\)/', '', $p['barangay'])) === $wantB
            )));
            if ($hits !== []) {
                $inCity = array_values(array_filter($hits, fn ($p) => array_intersect($cityForms($p['city']), $wantC) !== []));
                $pool = $inCity !== [] ? $inCity : $hits;
                usort($pool, fn ($a, $b) => $b['listings'] <=> $a['listings']);

                return $pool[0];
            }
        }

        if ($wantC !== []) {
            $hits = array_values(array_filter($places, fn ($p) => $p['barangay'] === null && array_intersect($cityForms($p['city']), $wantC) !== []));
            if ($hits !== []) {
                usort($hits, fn ($a, $b) => $b['listings'] <=> $a['listings']);

                return $hits[0];
            }
        }

        // A barangay typed where the city goes ("prices in Lahug"): try it as a barangay name.
        if ($wantB === '' && $wantC !== []) {
            $hits = array_values(array_filter($places, fn ($p) => $p['barangay'] !== null && in_array($norm($p['barangay']), $wantC, true)));
            if ($hits !== []) {
                usort($hits, fn ($a, $b) => $b['listings'] <=> $a['listings']);

                return $hits[0];
            }
        }

        return null;
    }

    /** The reply for a place we have: the team's "For Sale / For Rent, size: price" shape, in Markdown. */
    public function answerText(array $place, array $asked): string
    {
        $guide = $this->guide->guide($place['city_id'], $place['barangay_id']);
        $where = $place['barangay'] ? "{$place['barangay']}, {$place['city']}" : "{$place['city']}, {$place['province']}";
        if ($guide === null || $guide['sections'] === []) {
            return "I don't have enough live listings in **{$where}** yet to give a reliable price range. Try the city as a whole, or a nearby area.";
        }

        $sections = array_values(array_filter($guide['sections'], fn ($s) => $asked['category'] === '' || $s['category'] === $asked['category']));
        $lines = ["**{$where}** — typical asking prices from {$guide['totals']['listings']} live listings on Filipino Homes", ''];
        $wroteAny = false;

        foreach ($sections as $s) {
            $rent = $s['category'] === 'For Rent';
            $types = array_values(array_filter($s['types'], fn ($t) => $this->typeMatches($t['type'], $asked['property_type'])));
            $block = [];
            foreach ($types as $t) {
                $tiers = $t['tiers'];
                $sized = array_values(array_filter($tiers, fn ($x) => $x['tier'] !== 'All sizes'));
                // Asked for a size: only that row, and only types that have it (a "studio" question never shows houses' overall price).
                $rows = $asked['tier'] !== ''
                    ? array_values(array_filter($sized, fn ($x) => $x['tier'] === $asked['tier']))
                    : ($sized !== [] ? $sized : $tiers);
                if ($rows === []) {
                    continue;
                }
                $typeRows = [];
                foreach ($rows as $r) {
                    $label = $r['tier'] === 'All sizes' ? $t['type'] : ($asked['tier'] !== '' || count($types) > 1 ? "{$t['type']} {$r['tier']}" : $r['tier']);
                    $per = $rent ? '/month' : '';
                    // A Markdown list item: the chat renders Markdown, and plain indented lines would run together.
                    $typeRows[] = sprintf('- %s: **%s%s** (%s – %s)', $label, self::peso($r['median']), $per, self::peso($r['low']), self::peso($r['high']));
                }
                array_push($block, ...$typeRows);
            }
            if ($block === []) {
                continue;
            }
            $wroteAny = true;
            $lines[] = "**{$s['category']}**";
            array_push($lines, ...$block);
            $lines[] = '';
        }

        if (! $wroteAny) {
            $what = trim(implode(' ', array_filter([$asked['tier'], $asked['property_type'], $asked['category'] !== '' ? strtolower($asked['category']) : ''])));

            return "I don't have enough **{$what}** listings in **{$where}** to give a reliable range yet. Ask without the size or type, or try the whole city.";
        }

        $lines[] = 'The bold figure is the typical (median) asking price; the range is where the middle half of listings sit. Want me to pull up listings in '.($place['barangay'] ?: $place['city']).'?';

        return implode("\n", $lines);
    }

    /** When we can't tell where: ask, with a couple of places we do cover. */
    private function notFoundText(array $asked): string
    {
        $named = trim(implode(', ', array_filter([$asked['barangay'], $asked['city']])));
        $examples = array_slice(array_map(fn ($p) => $p['city'], array_filter($this->guide->places(), fn ($p) => $p['barangay'] === null)), 0, 4);
        $eg = $examples !== [] ? ' For example: '.implode(', ', $examples).'.' : '';

        return $named !== ''
            ? "I don't have enough live listings in **{$named}** yet to give price ranges. Which city or barangay would you like instead?{$eg}"
            : "Which city or barangay should I check prices for?{$eg} You can add a size too, like \"studio for rent in Lahug\".";
    }

    private function typeMatches(string $typeName, string $asked): bool
    {
        if ($asked === '') {
            return true;
        }
        $t = mb_strtolower($typeName);

        return match ($asked) {
            'Condominium' => str_contains($t, 'condo'),
            'House' => str_contains($t, 'house') || str_contains($t, 'town'),
            'Apartment' => str_contains($t, 'apart') || str_contains($t, 'room'),
            'Lot' => str_contains($t, 'lot') || str_contains($t, 'land') || str_contains($t, 'farm'),
            'Commercial' => str_contains($t, 'commercial') || str_contains($t, 'office') || str_contains($t, 'warehouse') || str_contains($t, 'building'),
            default => str_contains($t, mb_strtolower($asked)),
        };
    }

    /** ₱3.2M / ₱18K / ₱950, the way the site writes money. */
    public static function peso(float|int $v): string
    {
        // One decimal under 10 units ("₱3.2M"), none from 10 up ("₱14M"); a trailing ".0" is dropped, never a real zero ("₱40K").
        $short = fn (float $n) => $n >= 10 ? number_format($n, 0) : rtrim(rtrim(number_format($n, 1), '0'), '.');
        if ($v >= 1_000_000) {
            return '₱'.$short($v / 1_000_000).'M';
        }
        if ($v >= 1_000) {
            return '₱'.$short($v / 1_000).'K';
        }

        return '₱'.number_format($v);
    }
}
