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
        $key = 'landmark:v2:'.md5(mb_strtolower($name).'|'.mb_strtolower($cityHint));
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit === false ? null : $hit;
        }

        $found = ($isCity ? null : $this->fromProjects($name)) ?? $this->fromNominatim($name, $cityHint);
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

    private function fromNominatim(string $name, string $cityHint): ?array
    {
        $queries = array_values(array_unique(array_filter([
            $cityHint !== '' ? "{$name}, {$cityHint}, Philippines" : null,
            "{$name}, Philippines",
        ])));
        foreach ($queries as $q) {
            try {
                $res = Http::withHeaders(['User-Agent' => 'FilipinoHomes-ASA/1.0 (https://filipinohomes.com)'])
                    ->timeout(4)
                    ->get('https://nominatim.openstreetmap.org/search', ['q' => $q, 'format' => 'jsonv2', 'limit' => 5, 'countrycodes' => 'ph', 'dedupe' => 1]);
                $rows = $res->ok() ? array_values(array_filter((array) $res->json(), fn ($r) => is_array($r) && isset($r['lat'], $r['lon']))) : [];
                if ($rows === []) {
                    continue;
                }
                // Prefer the result that is actually named like the place ("Ayala Center Cebu" over a shop
                // inside it); among those, the most important one. Fall back to the first.
                $words = array_filter(preg_split('/\W+/u', mb_strtolower($name)), fn ($w) => mb_strlen($w) > 2);
                $named = array_values(array_filter($rows, function (array $r) use ($words) {
                    $hay = mb_strtolower((string) ($r['name'] ?? '')).' '.mb_strtolower((string) ($r['display_name'] ?? ''));
                    foreach ($words as $w) {
                        if (! str_contains($hay, $w)) {
                            return false;
                        }
                    }

                    return true;
                }));
                $pool = $named !== [] ? $named : $rows;
                usort($pool, fn ($a, $b) => (float) ($b['importance'] ?? 0) <=> (float) ($a['importance'] ?? 0));
                $pick = $pool[0];
                $label = trim((string) ($pick['name'] ?? '')) ?: implode(', ', array_slice(array_map('trim', explode(',', (string) ($pick['display_name'] ?? $name))), 0, 2));

                $point = self::philippinePoint((float) $pick['lat'], (float) $pick['lon']);
                if ($point === null) {
                    continue;
                }

                return $point + ['label' => $label ?: $name, 'source' => 'osm'];
            } catch (\Throwable) {
                // fall through to the next query, then to null
            }
        }

        return null;
    }
}
