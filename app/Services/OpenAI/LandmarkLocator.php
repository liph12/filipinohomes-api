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
    /** @return array{lat: float, lng: float, label: string, source: string}|null */
    public function locate(string $name, string $cityHint = ''): ?array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 3) {
            return null;
        }
        $key = 'landmark:v1:'.md5(mb_strtolower($name).'|'.mb_strtolower($cityHint));
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit === false ? null : $hit;
        }

        $found = $this->fromProjects($name) ?? $this->fromNominatim($name, $cityHint);
        if ($found === null) {
            Cache::put($key, false, 86400);

            return null;
        }
        Cache::forever($key, $found);

        return $found;
    }

    private function fromProjects(string $name): ?array
    {
        $row = DB::table('projects')
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where('latitude', '!=', '')->where('longitude', '!=', '')
            ->where('name', 'LIKE', '%'.$name.'%')
            ->orderByDesc('views')
            ->first(['name', 'latitude', 'longitude']);
        if ($row === null || ! is_numeric($row->latitude) || ! is_numeric($row->longitude)) {
            return null;
        }

        return ['lat' => (float) $row->latitude, 'lng' => (float) $row->longitude, 'label' => (string) $row->name, 'source' => 'projects'];
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

                return ['lat' => (float) $pick['lat'], 'lng' => (float) $pick['lon'], 'label' => $label ?: $name, 'source' => 'osm'];
            } catch (\Throwable) {
                // fall through to the next query, then to null
            }
        }

        return null;
    }
}
