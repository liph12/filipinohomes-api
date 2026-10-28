<?php

namespace App\Services\OpenAI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Where a named place is, for "condos near IT Park": a point to measure listings from.
 *
 * Two sources, in order. Our own `projects` table first — 544 developments with coordinates, so
 * "near Avida Towers Riala" never leaves the database. Then OpenStreetMap's Nominatim, limited to the
 * Philippines and biased by the city the user named; free, so each answer is cached for good and a
 * miss is cached for a day (it is rate-limited to one request a second, which the cache keeps us
 * well under). Null when neither knows the place — the search then falls back to plain text.
 */
class LandmarkLocator
{
    public function __construct(private readonly \App\Services\Listing\PriceGuideService $guide)
    {
    }

    /** @return array{lat: float, lng: float, label: string, source: string}|null */
    public function locate(string $name, string $cityHint = ''): ?array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 3) {
            return null;
        }
        // A city's name is not a landmark: "near Cebu City" must not resolve to a development
        // that happens to carry the city in its name.
        $isCity = false;
        try {
            $isCity = ($this->guide->placeOf($name)['kind'] ?? null) === 'city';
        } catch (\Throwable) {
        }
        $key = 'landmark:v3:'.md5(mb_strtolower($name).'|'.mb_strtolower($cityHint));
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit === false ? null : $hit;
        }

        // Generic landmarks (a park, mall, airport, hospital, school, church, terminal) are places on
        // the map, not developments: a project called "… near IT Park" must not answer for IT Park.
        $generic = (bool) preg_match('/\b(park|mall|center|centre|airport|hospital|school|university|college|church|cathedral|terminal|port|pier|plaza|market|city hall|capitol|stadium|station|bridge|beach|highway|road|avenue|street)\b/i', $name);
        $found = ($isCity || $generic ? null : $this->fromProjects($name)) ?? $this->fromNominatim($name, $cityHint);
        if ($found === null) {
            Cache::put($key, false, 86400);

            return null;
        }
        Cache::forever($key, $found);

        return $found;
    }

    private function fromProjects(string $name): ?array
    {
        // Match the development's own name, not the city tacked on after the comma ("Vista
        // Residences Kamputhaw, Cebu City" must not answer for "Cebu City").
        $row = DB::table('projects')
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where('latitude', '!=', '')->where('longitude', '!=', '')
            ->whereRaw("SUBSTRING_INDEX(name, ',', 1) LIKE ?", ['%'.$name.'%'])
            // "Mesavirre Residences near IT Park" names its neighbour, not itself.
            ->whereRaw("SUBSTRING_INDEX(name, ',', 1) NOT REGEXP ?", ['(^|[^a-z])(near|beside|along|across|fronting|infront|in front|walking distance|minutes? (to|from)|close to)([^a-z]|$)'])
            ->orderByDesc('views')
            ->first(['name', 'latitude', 'longitude']);
        if ($row === null || ! is_numeric($row->latitude) || ! is_numeric($row->longitude)) {
            return null;
        }
        $point = self::philippinePoint((float) $row->latitude, (float) $row->longitude);
        if ($point === null) {
            return null;
        }

        return $point + ['label' => (string) $row->name, 'source' => 'projects'];
    }

    /**
     * A lat/lng inside the Philippines, swapping the pair when it was stored the other way round
     * (some projects carry latitude 123.9, longitude 10.3); null when it is nowhere near.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function philippinePoint(float $lat, float $lng): ?array
    {
        $inside = fn (float $la, float $lo) => $la >= 4.0 && $la <= 22.0 && $lo >= 116.0 && $lo <= 127.5;
        if ($inside($lat, $lng)) {
            return ['lat' => $lat, 'lng' => $lng];
        }
        if ($inside($lng, $lat)) {
            return ['lat' => $lng, 'lng' => $lat];
        }

        return null;
    }

    /** How many live listings sit within about 3 km of a point — a cheap bounding-box count. */
    private function listingsAround(float $lat, float $lng): int
    {
        $p = self::philippinePoint($lat, $lng);
        if ($p === null) {
            return 0;
        }
        try {
            $d = 0.027; // ~3 km in degrees of latitude
            return (int) DB::table('listings')
                ->join('properties', 'properties.id', '=', 'listings.property_id')
                ->whereNull('listings.deleted_at')
                ->where('listings.visibility', 'public')
                ->whereRaw("JSON_EXTRACT(properties.geo_coordinates, '$.lat') BETWEEN ? AND ?", [$p['lat'] - $d, $p['lat'] + $d])
                ->whereRaw("JSON_EXTRACT(properties.geo_coordinates, '$.lng') BETWEEN ? AND ?", [$p['lng'] - $d, $p['lng'] + $d])
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function fromNominatim(string $name, string $cityHint): ?array
    {
        // With no city named, "IT Park" alone finds Bacolod's and Nabua's but not Cebu's (OSM calls it
        // "Cebu I.T. Park"), so the name is also tried in the cities with the most listings; the
        // candidate with the most of our listings around it wins. One request a second, as OSM asks.
        $queries = [$cityHint !== '' ? "{$name}, {$cityHint}, Philippines" : null, "{$name}, Philippines"];
        if ($cityHint === '') {
            foreach (array_slice($this->guide->areas(), 0, 5) as $area) {
                $queries[] = "{$name}, {$area['city']}, Philippines";
            }
        }
        $queries = array_values(array_unique(array_filter($queries)));
        $words = array_filter(preg_split('/\W+/u', mb_strtolower($name)), fn ($w) => mb_strlen($w) > 2);
        $candidates = [];
        foreach ($queries as $i => $q) {
            if ($i > 0) {
                usleep(1100000);
            }
            try {
                $res = Http::withHeaders(['User-Agent' => 'FilipinoHomes-ASA/1.0 (https://filipinohomes.com)'])
                    ->timeout(4)
                    ->get('https://nominatim.openstreetmap.org/search', ['q' => $q, 'format' => 'jsonv2', 'limit' => 5, 'countrycodes' => 'ph', 'dedupe' => 1]);
                $rows = $res->ok() ? array_values(array_filter((array) $res->json(), fn ($r) => is_array($r) && isset($r['lat'], $r['lon']))) : [];
            } catch (\Throwable) {
                $rows = [];
            }
            foreach ($rows as $r) {
                $key = round((float) $r['lat'], 3).','.round((float) $r['lon'], 3);
                $candidates[$key] ??= $r;
            }
            // A city hint that found the place is answer enough; the broad queries are for bare names.
            if ($cityHint !== '' && $candidates !== []) {
                break;
            }
        }
        if ($candidates === []) {
            return null;
        }
        $candidates = array_values($candidates);

        // Prefer the results actually named like the place ("Ayala Center Cebu" over a shop inside it).
        $named = array_values(array_filter($candidates, function (array $r) use ($words) {
            $hay = mb_strtolower((string) ($r['name'] ?? '')).' '.mb_strtolower((string) ($r['display_name'] ?? ''));
            foreach ($words as $w) {
                if (! str_contains($hay, $w)) {
                    return false;
                }
            }

            return true;
        }));
        $pool = $named !== [] ? $named : $candidates;
        // Several places share a name: the one with the most of our own listings around it is the one
        // a buyer here means; OSM's importance breaks ties.
        $scored = array_map(fn (array $r) => ['row' => $r, 'n' => count($pool) > 1 ? $this->listingsAround((float) $r['lat'], (float) $r['lon']) : 0], $pool);
        usort($scored, fn ($a, $b) => [$b['n'], (float) ($b['row']['importance'] ?? 0)] <=> [$a['n'], (float) ($a['row']['importance'] ?? 0)]);
        $pick = $scored[0]['row'];
        $point = self::philippinePoint((float) $pick['lat'], (float) $pick['lon']);
        if ($point === null) {
            return null;
        }
        $label = trim((string) ($pick['name'] ?? '')) ?: implode(', ', array_slice(array_map('trim', explode(',', (string) ($pick['display_name'] ?? $name))), 0, 2));

        return $point + ['label' => $label ?: $name, 'source' => 'osm'];
    }
}