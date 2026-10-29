<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * What is around a point, for the listings whose agent never saved nearby places — most of them:
 * 756 of 10,328 public listings carry a nearby_facilities row. One OpenStreetMap Overpass query for
 * everything named within 3 km (schools, hospitals, clinics, pharmacies, shops, parks, churches,
 * transport, police, fire stations, sights), sorted by straight-line distance, in the same
 * `category => [{name, address, lat, lng, distance_meters}]` shape the stored rows use so one panel
 * draws both. Free and rate-limited, so a point's answer is cached for 30 days (the map does not
 * change week to week) and a failure for 10 minutes. The public instances can take ten seconds or
 * more to answer, so the chat tries the places saved on neighbouring listings first and comes here
 * only when those are thin.
 */
class NearbyPlacesFinder
{
    public const RADIUS_M = 3000;

    public const PER_CATEGORY = 4;

    /** OSM tag value → our category, per tag key. The first key that matches decides. */
    private const TAGS = [
        'amenity' => [
            'school' => 'school', 'kindergarten' => 'school', 'college' => 'school', 'university' => 'school',
            'hospital' => 'hospital',
            'clinic' => 'clinic', 'doctors' => 'clinic', 'dentist' => 'clinic',
            'pharmacy' => 'pharmacy',
            'police' => 'police_station',
            'fire_station' => 'fire_station',
            'place_of_worship' => 'church',
            'bus_station' => 'transport_station',
            'marketplace' => 'mall',
        ],
        'shop' => ['mall' => 'mall', 'department_store' => 'mall', 'supermarket' => 'mall'],
        'leisure' => ['park' => 'park', 'playground' => 'park', 'sports_centre' => 'park'],
        'tourism' => ['attraction' => 'attraction', 'museum' => 'attraction', 'viewpoint' => 'attraction', 'theme_park' => 'attraction'],
        'railway' => ['station' => 'transport_station'],
        'public_transport' => ['station' => 'transport_station'],
    ];

    /**
     * Named places within RADIUS_M of the point, nearest first, at most PER_CATEGORY per category.
     * `[]` when the map has nothing there; null when OpenStreetMap could not be reached.
     *
     * @return array<string, array<int, array{name: string, address: string, lat: float, lng: float, distance_meters: int}>>|null
     */
    public function around(float $lat, float $lng): ?array
    {
        $key = sprintf('nearby:v1:%.3f,%.3f', $lat, $lng);
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit === false ? null : $hit;
        }
        $elements = $this->query($lat, $lng);
        if ($elements === null) {
            Cache::put($key, false, 600);

            return null;
        }
        $grouped = $this->group($elements, $lat, $lng);
        Cache::put($key, $grouped, 30 * 86400);

        return $grouped;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function query(float $lat, float $lng): ?array
    {
        $around = sprintf('(around:%d,%.6f,%.6f)', self::RADIUS_M, $lat, $lng);
        $ql = "[out:json][timeout:8];\n(\n"
            .'  nwr["amenity"~"^('.implode('|', array_keys(self::TAGS['amenity'])).')$"]["name"]'.$around.";\n"
            .'  nwr["shop"~"^('.implode('|', array_keys(self::TAGS['shop'])).')$"]["name"]'.$around.";\n"
            .'  nwr["leisure"~"^('.implode('|', array_keys(self::TAGS['leisure'])).')$"]["name"]'.$around.";\n"
            .'  nwr["tourism"~"^('.implode('|', array_keys(self::TAGS['tourism'])).')$"]["name"]'.$around.";\n"
            .'  nwr["railway"="station"]["name"]'.$around.";\n"
            .'  nwr["public_transport"="station"]["name"]'.$around.";\n"
            .");\nout tags center 800;";

        $endpoints = array_values(array_unique(array_filter([
            (string) config('services.overpass.endpoint'),
            (string) config('services.overpass.fallback_endpoint'),
        ])));
        foreach ($endpoints as $endpoint) {
            try {
                $res = Http::timeout(9)
                    ->withHeaders(['User-Agent' => (string) config('services.overpass.user_agent', 'FilipinoHomes-ASA/1.0 (https://filipinohomes.com)')])
                    ->asForm()
                    ->post($endpoint, ['data' => $ql]);
                $json = $res->ok() ? $res->json() : null;
                if (is_array($json) && isset($json['elements']) && stripos((string) ($json['remark'] ?? ''), 'runtime error') === false) {
                    return $json['elements'];
                }
                Log::info('NearbyPlacesFinder: no usable answer', ['endpoint' => $endpoint, 'status' => $res->status()]);
            } catch (\Throwable $e) {
                Log::info('NearbyPlacesFinder: request failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return array<string, array<int, array{name: string, address: string, lat: float, lng: float, distance_meters: int}>>
     */
    private function group(array $elements, float $lat, float $lng): array
    {
        $out = [];
        $seen = [];
        foreach ($elements as $el) {
            $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
            $name = trim((string) ($tags['name'] ?? ''));
            $pLat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $pLng = $el['lon'] ?? $el['center']['lon'] ?? null;
            if ($name === '' || ! is_numeric($pLat) || ! is_numeric($pLng)) {
                continue;
            }
            $category = null;
            foreach (self::TAGS as $tag => $map) {
                $v = (string) ($tags[$tag] ?? '');
                if ($v !== '' && isset($map[$v])) {
                    $category = $map[$v];
                    break;
                }
            }
            if ($category === null) {
                continue;
            }
            $dedupe = $category.'|'.mb_strtolower($name);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $address = implode(', ', array_filter([trim((string) ($tags['addr:street'] ?? '')), trim((string) ($tags['addr:city'] ?? ''))]));
            $out[$category][] = [
                'name' => $name,
                'address' => $address,
                'lat' => (float) $pLat,
                'lng' => (float) $pLng,
                'distance_meters' => (int) round(self::metres($lat, $lng, (float) $pLat, (float) $pLng)),
            ];
        }
        foreach ($out as &$items) {
            usort($items, fn ($a, $b) => $a['distance_meters'] <=> $b['distance_meters']);
            $items = array_slice($items, 0, self::PER_CATEGORY);
        }

        return $out;
    }

    /** Straight-line distance between two points, in metres. */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(sqrt($h));
    }
}
