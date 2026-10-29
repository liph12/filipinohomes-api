<?php

namespace App\Services\OpenAI;

use App\Models\Listing;
use App\Models\NearbyFacility;
use App\Services\Listing\FacilityIndex;
use App\Services\Listing\NearbyFacilityCleaner;
use App\Services\Listing\NearbyPlacesFinder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "what's nearby?" — the schools, hospitals, pharmacies, shops, parks, churches and
 * transport around a listing, with distances.
 *
 * No model: the listing is the one named by id ("What's nearby? listing #123") or, for a typed
 * "any schools near it?", the one most recently shown in this thread. The places come, in order,
 * from the agent's own saved nearby_facilities row (the wizard looks them up at creation; 756 of
 * 10,328 public listings have one), from the rows saved on other listings within 6 km re-measured
 * from this pin (instant, and 87% of pinned listings have one within 5 km), and only then from
 * OpenStreetMap on demand (NearbyPlacesFinder, slow and cached). Distances are straight-line from
 * the property's pin; a listing without a pin cannot be looked around.
 */
class NearbyCommandService extends CacheService
{
    use InsightStream;

    /** A typed nearby question: "what's near it", "any schools nearby", "malapit ba sa mall". */
    public const PATTERN = '/\b(what(?:\'| i)?s|whats|anything|things|places|is there|are there|any|may|meron|mayroon)\b.{0,40}\b(near|nearby|near ?by|around|close ?by|malapit)\b|\b(nearby|near ?by)\b|\bmalapit\b|\bwhat(?:\'| i)?s around\b/iu';

    /** The question points at a listing already on screen rather than describing a search. */
    private const REFERS_TO_SHOWN = '/\b(it|this|that|there|here|the (?:property|house|unit|condo|listing|place|one|lot|area))\b/iu';

    /** A search, not a nearby question: "any condos near IT Park under 5M". */
    private const SEARCH_CUE = '/\b(for (?:sale|rent)|show me|find|looking for|search|under|below|budget|condos?|houses?|lots?|apartments?|townhouses?|studios?|bedrooms?|listings?|properties)\b|₱|\b\d+\s*(?:m|k|million)\b/iu';

    /** Category words a buyer may ask for, to put that group first. */
    private const WANTED = [
        'school' => '/\b(schools?|college|universit|eskwela|paaralan|kinder|daycare)/iu',
        'hospital' => '/\b(hospitals?|ospital|emergency)/iu',
        'clinic' => '/\b(clinics?|doctors?|dentist)/iu',
        'pharmacy' => '/\b(pharmac|drugstore|botika|mercury drug)/iu',
        'mall' => '/\b(malls?|shopping|supermarket|grocery|groceries|market|palengke)/iu',
        'park' => '/\b(parks?|playground|jog)/iu',
        'church' => '/\b(church|chapel|simbahan|parish|mosque)/iu',
        'transport_station' => '/\b(transport|terminal|station|bus|jeep|mrt|lrt|train|commute)/iu',
        'police_station' => '/\b(police|pulis)/iu',
        'fire_station' => '/\b(fire station|bombero)/iu',
        'attraction' => '/\b(attraction|tourist|sights?|landmark|museum)/iu',
    ];

    /** How far away another listing's saved places may be borrowed from. */
    private const BORROW_M = 6000;

    /** Fewer borrowed places than this and OpenStreetMap is asked too. */
    private const BORROW_ENOUGH = 3;

    private const LABELS = [
        'school' => 'Schools',
        'hospital' => 'Hospitals',
        'clinic' => 'Clinics',
        'pharmacy' => 'Pharmacies',
        'mall' => 'Shopping',
        'park' => 'Parks',
        'church' => 'Churches',
        'transport_station' => 'Transport',
        'police_station' => 'Police',
        'fire_station' => 'Fire stations',
        'attraction' => 'Places to see',
    ];

    private const SINGULAR = [
        'school' => 'school',
        'hospital' => 'hospital',
        'clinic' => 'clinic',
        'pharmacy' => 'pharmacy',
        'mall' => 'mall',
        'park' => 'park',
        'church' => 'church',
        'transport_station' => 'transport stop',
        'police_station' => 'police station',
        'fire_station' => 'fire station',
        'attraction' => 'place to see',
    ];

    public function __construct(private readonly DataLayerService $data, private readonly NearbyPlacesFinder $finder, private readonly FacilityIndex $index)
    {
    }

    /** A typed question is about the listing on screen, not a new search. */
    public static function aboutShownListing(string $question): bool
    {
        return preg_match(self::REFERS_TO_SHOWN, $question) === 1 || preg_match(self::SEARCH_CUE, $question) !== 1;
    }

    /** The listings most recently shown in this thread, for a typed question with no id. */
    public function recentIds(Request $req): array
    {
        return $this->recentListingIds($req);
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function replyStream(array $thread, Request $req, array $ids = []): StreamedResponse
    {
        $last = collect($thread)->where('role', 'user')->last();
        $question = is_array($last) ? trim((string) ($last['content'] ?? '')) : '';
        if ($ids === []) {
            if (preg_match_all('/\blisting\s*#\s*(\d+)/i', $question, $mm) && $mm[1] !== []) {
                $ids = array_values(array_unique(array_map('intval', $mm[1])));
            } else {
                $ids = $this->recentListingIds($req);
            }
        }
        if ($ids === []) {
            AsaQuestionLog::outcome($req, 'no_data');

            return $this->streamInsight('nearby', 'nearby', ['empty' => true], "Which property do you mean? Ask me for listings first — for example **\"house and lot in Cebu City under ₱5M\"** — then tap **What's nearby?** on a card and I'll list the schools, hospitals, shops and transport around it.", [], $req);
        }

        $result = $this->insight((int) $ids[0], $question);
        if (is_string($result)) {
            AsaQuestionLog::outcome($req, 'no_data');

            return $this->streamInsight('nearby', 'nearby', ['empty' => true], $result, [], $req);
        }

        $id = $result['listing_id'];
        $followUps = [
            ['label' => 'Where is it?', 'prompt' => "Where is this? listing #{$id}"],
            ['label' => 'How far from the city centre?', 'prompt' => sprintf('How far is listing #%d from %s city hall?', $id, $result['city'] ?: 'the')],
            ['label' => 'Is it a good deal?', 'prompt' => "Is this a good deal? listing #{$id}"],
            ['label' => 'Total cost to buy', 'prompt' => "What will it really cost to buy listing #{$id}?"],
        ];
        AsaQuestionLog::outcome($req, 'answered', $result['spot'] ?: null);

        return $this->streamInsight('nearby', 'nearby', $result['data'], $result['text'], $followUps, $req);
    }

    /**
     * The places around one listing as panel data plus the one-line write-up, or the sentence to
     * send instead when there is nothing to show.
     *
     * @return array{listing_id: int, city: ?string, spot: string, data: array<string, mixed>, text: string}|string
     */
    public function insight(int $listingId, string $question = ''): array|string
    {
        $listing = Listing::publiclyListed()->with(['property'])->find($listingId);
        if ($listing === null) {
            return "That listing isn't live any more, so I can't look around it. Ask me for listings again and I'll check the new ones.";
        }
        $name = trim((string) ($listing->property->name ?? $listing->name)) ?: $listing->name;
        $geo = $listing->property?->geo_coordinates;
        $geo = is_array($geo) ? $geo : (is_string($geo) ? (json_decode($geo, true) ?: []) : []);
        $point = isset($geo['lat'], $geo['lng']) && is_numeric($geo['lat']) && is_numeric($geo['lng'])
            ? LandmarkLocator::philippinePoint((float) $geo['lat'], (float) $geo['lng'])
            : null;
        if ($point === null) {
            return "I don't have a map pin for **{$name}** yet, so I can't look around it — the agent hasn't placed it on the map. Ask the agent and they can tell you what's close by.";
        }

        [$places, $source] = $this->placesFor((int) $listing->property_id, $point['lat'], $point['lng']);
        if ($places === null) {
            return "I couldn't reach the map service to look around **{$name}** just now. Try again in a minute, or tap **Where is it?** to see it on the map.";
        }

        $wanted = [];
        foreach (self::WANTED as $key => $re) {
            if ($question !== '' && preg_match($re, $question)) {
                $wanted[] = $key;
            }
        }
        $groups = [];
        foreach (array_merge($wanted, array_keys(self::LABELS)) as $key) {
            if (isset($groups[$key]) || empty($places[$key])) {
                continue;
            }
            $items = [];
            foreach ($places[$key] as $p) {
                if (! FacilityIndex::looksReal($key, (string) ($p['name'] ?? ''))) {
                    continue;
                }
                $m = (int) round((float) ($p['distance_meters'] ?? 0));
                if ($m <= 0 && isset($p['lat'], $p['lng']) && is_numeric($p['lat']) && is_numeric($p['lng'])) {
                    $m = (int) round(NearbyPlacesFinder::metres($point['lat'], $point['lng'], (float) $p['lat'], (float) $p['lng']));
                }
                $items[] = [
                    'name' => (string) $p['name'],
                    'address' => (string) ($p['address'] ?? ''),
                    'lat' => isset($p['lat']) && is_numeric($p['lat']) ? (float) $p['lat'] : null,
                    'lng' => isset($p['lng']) && is_numeric($p['lng']) ? (float) $p['lng'] : null,
                    'distance_meters' => $m,
                    'walk_min' => $m > 0 ? max(1, (int) round($m / 80)) : null,
                ];
            }
            if ($items === []) {
                continue;
            }
            usort($items, fn ($a, $b) => ($a['distance_meters'] ?: PHP_INT_MAX) <=> ($b['distance_meters'] ?: PHP_INT_MAX));
            $groups[$key] = ['key' => $key, 'label' => self::LABELS[$key], 'items' => array_slice($items, 0, NearbyPlacesFinder::PER_CATEGORY)];
        }
        $groups = array_values($groups);
        if ($groups === []) {
            return "Nothing is mapped within 3 km of **{$name}** yet — not a school, shop or clinic that I can name. Ask the agent what's close by; they'll know the area.";
        }

        $all = array_merge(...array_map(fn ($g) => $g['items'], $groups));
        $within1km = count(array_filter($all, fn ($p) => $p['distance_meters'] > 0 && $p['distance_meters'] <= 1000));
        $address = (string) ($listing->property->address ?? '');
        $city = self::cityOf($address);
        $spot = self::spotOf($address, $name) ?? $city ?? '';
        $where = $spot !== '' ? " in **{$spot}**" : '';

        $text = $this->writeUp($name, $where, $groups, $wanted, $within1km, count($all), $source);
        $card = $this->data->cardFor((int) $listing->id);

        return [
            'listing_id' => (int) $listing->id,
            'city' => $city,
            'spot' => $spot,
            'text' => $text,
            'data' => [
                'listing_id' => (int) $listing->id,
                'listing' => $card,
                'name' => $name,
                'address' => trim((string) preg_replace('/,\s*(Philippines|\d{4})\s*$/i', '', $address)),
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'radius_m' => NearbyPlacesFinder::RADIUS_M,
                'source' => $source,
                'wanted' => $wanted,
                'groups' => $groups,
                'counts' => ['total' => count($all), 'within_1km' => $within1km],
            ],
        ];
    }

    /**
     * @param  array<int, array{key: string, label: string, items: array<int, array<string, mixed>>}>  $groups
     * @param  array<int, string>  $wanted
     */
    private function writeUp(string $name, string $where, array $groups, array $wanted, int $within1km, int $total, string $source): string
    {
        $byKey = array_column($groups, null, 'key');
        $sourceNote = match ($source) {
            'listing' => 'These are the places the agent saved with the listing.',
            'listings' => 'Measured from the places saved on listings around it.',
            default => 'From OpenStreetMap, so a newer shop or school may not be on it yet.',
        };

        if ($wanted !== []) {
            $found = array_values(array_filter($wanted, fn ($k) => isset($byKey[$k])));
            if ($found === []) {
                $asked = implode(' or ', array_map(fn ($k) => self::SINGULAR[$k], $wanted));
                $sentence = sprintf("I found no %s mapped within 3 km of **%s**%s. Here is what is around it instead.", $asked, $name, $where);
            } else {
                $parts = [];
                foreach (array_slice($found, 0, 2) as $k) {
                    $items = $byKey[$k]['items'];
                    $first = $items[0];
                    $line = sprintf('the nearest %s is **%s**, %s away', self::SINGULAR[$k], $first['name'], self::distance($first['distance_meters']));
                    if ($first['walk_min'] !== null && $first['distance_meters'] <= 2000) {
                        $line .= sprintf(' (about a %d-minute walk)', $first['walk_min']);
                    }
                    if (count($items) > 1) {
                        $line .= sprintf(', then **%s** at %s', $items[1]['name'], self::distance($items[1]['distance_meters']));
                    }
                    $parts[] = $line;
                }
                $sentence = sprintf('Around **%s**%s, %s.', $name, $where, implode('; ', $parts));
            }

            return $sentence.' '.$sourceNote;
        }

        $leads = [];
        foreach (['school', 'hospital', 'mall', 'transport_station'] as $k) {
            if (isset($byKey[$k])) {
                $first = $byKey[$k]['items'][0];
                if ($k === 'hospital') {
                    // The nearest real hospital leads, not the clinic Google filed under hospitals.
                    foreach ($byKey[$k]['items'] as $cand) {
                        if (preg_match('/hospital|medical cent|ospital/i', $cand['name'])) {
                            $first = $cand;
                            break;
                        }
                    }
                }
                $leads[] = sprintf('%s **%s** (%s)', self::SINGULAR[$k], $first['name'], self::distance($first['distance_meters']));
            }
        }
        if ($leads === []) {
            $first = $groups[0]['items'][0];
            $leads[] = sprintf('%s **%s** (%s)', self::SINGULAR[$groups[0]['key']], $first['name'], self::distance($first['distance_meters']));
        }
        $count = $within1km > 0
            ? sprintf('**%d** places within 3 km, **%d** of them under a kilometre away', $total, $within1km)
            : sprintf('**%d** places within 3 km', $total);

        return sprintf('Around **%s**%s: %s — the nearest %s. %s', $name, $where, $count, implode(', ', $leads), $sourceNote);
    }

    /**
     * The agent's saved places when the row has any, else OpenStreetMap; null when neither answered.
     *
     * @return array{0: array<string, array<int, array<string, mixed>>>|null, 1: string}
     */
    private function placesFor(int $propertyId, float $lat, float $lng): array
    {
        $row = NearbyFacility::where('property_id', $propertyId)->first();
        if ($row !== null) {
            $cleaned = array_filter(self::cleanRow($row), fn ($items) => $items !== []);
            if ($cleaned !== []) {
                return [$cleaned, 'listing'];
            }
        }

        $borrowed = $this->borrowed($lat, $lng);
        if (array_sum(array_map('count', $borrowed)) >= self::BORROW_ENOUGH) {
            return [$borrowed, 'listings'];
        }
        $osm = $this->finder->around($lat, $lng);
        if ($osm !== null && $osm !== []) {
            return [$osm, 'osm'];
        }
        if ($borrowed !== []) {
            return [$borrowed, 'listings'];
        }

        return [$osm, 'osm'];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private static function cleanRow(NearbyFacility $row): array
    {
        return NearbyFacilityCleaner::clean(
            collect(NearbyFacilityCleaner::CATEGORIES)->mapWithKeys(fn ($c) => [$c => $row->{$c} ?? []])->all()
        );
    }

    /**
     * The places saved on other listings within BORROW_M of the point, re-measured from it and kept
     * only within the panel's radius — nearest PER_CATEGORY per category, each place once.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function borrowed(float $lat, float $lng): array
    {
        return $this->index->around($lat, $lng, NearbyPlacesFinder::RADIUS_M, NearbyPlacesFinder::PER_CATEGORY);
    }

    /** @deprecated the FacilityIndex answers this now; kept until the next cleanup. */
    private function borrowedByQuery(float $lat, float $lng): array
    {
        $dLat = self::BORROW_M / 111000;
        $dLng = $dLat / max(cos(deg2rad($lat)), 0.2);
        try {
            $rows = NearbyFacility::query()
                ->join('properties as p', 'p.id', '=', 'nearby_facilities.property_id')
                ->whereRaw("JSON_EXTRACT(p.geo_coordinates, '$.lat') BETWEEN ? AND ?", [$lat - $dLat, $lat + $dLat])
                ->whereRaw("JSON_EXTRACT(p.geo_coordinates, '$.lng') BETWEEN ? AND ?", [$lng - $dLng, $lng + $dLng])
                ->select('nearby_facilities.*')
                ->limit(40)
                ->get();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            foreach (self::cleanRow($row) as $cat => $items) {
                foreach ($items as $p) {
                    if (! isset($p['lat'], $p['lng']) || ! is_numeric($p['lat']) || ! is_numeric($p['lng'])) {
                        continue;
                    }
                    $m = NearbyPlacesFinder::metres($lat, $lng, (float) $p['lat'], (float) $p['lng']);
                    $key = $cat.'|'.mb_strtolower((string) $p['name']);
                    if ($m > NearbyPlacesFinder::RADIUS_M || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[$cat][] = array_merge($p, ['distance_meters' => (int) round($m)]);
                }
            }
        }
        foreach ($out as &$items) {
            usort($items, fn ($a, $b) => $a['distance_meters'] <=> $b['distance_meters']);
            $items = array_slice($items, 0, NearbyPlacesFinder::PER_CATEGORY);
        }

        return $out;
    }

    public static function distance(int $m): string
    {
        if ($m <= 0) {
            return 'close by';
        }

        return $m < 1000 ? "{$m} m" : sprintf('%.1f km', $m / 1000);
    }

    /**
     * The neighbourhood to say the listing is "in": the first short address part that is not the
     * listing's own name ("38 Park Avenue, Cebu IT Park, Cebu City" → "Cebu IT Park"), or null.
     */
    private static function spotOf(string $address, string $name): ?string
    {
        foreach (array_map('trim', explode(',', $address)) as $part) {
            if ($part === '' || mb_strlen($part) > 32 || preg_match('/^\d{4}$/', $part) || strcasecmp($part, 'Philippines') === 0) {
                continue;
            }
            if (stripos($name, $part) !== false || stripos($part, $name) !== false) {
                continue;
            }

            return $part;
        }

        return null;
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
