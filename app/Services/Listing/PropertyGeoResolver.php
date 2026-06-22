<?php

namespace App\Services\Listing;

use App\Services\Office\GoogleGeocodingService;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a property's TRUE location from its map pin (geo_coordinates) by
 * reverse-geocoding, then caches the matched city + barangay ids on the
 * property. The agent-picked barangay dropdown (address_id) is unreliable; the
 * pin is the agent's actual placement, so analytics should group by this.
 *
 * Only the CITY needs to match reliably — province + island derive from the
 * city's province via the trusted cities→provinces hierarchy. Barangay is
 * best-effort.
 */
class PropertyGeoResolver
{
    /** Lazily-built [normalizedCityName => [['id'=>int,'province'=>string], ...]]. */
    private ?array $cityIndex = null;

    public function __construct(private GoogleGeocodingService $geocoder) {}

    /**
     * Resolve + persist geo_city_id / geo_barangay_id for a property.
     * Returns true if it (re)resolved, false if skipped/failed.
     *
     * @param object $property row/model with ->id and ->geo_coordinates
     */
    public function resolve(object $property, bool $force = false): bool
    {
        if (! $force && ! empty($property->geo_geocoded_at)) {
            return false; // already cached
        }

        $coords = $this->parseCoords($property->geo_coordinates ?? null);
        if ($coords === null) {
            return false;
        }

        try {
            $components = $this->geocoder->reverseGeocode($coords['lat'], $coords['lng']);
        } catch (\Throwable $e) {
            return false; // transient API failure — leave for a later run
        }

        $cityId = $components ? $this->matchCity($components['locality'] ?? null, $components['province'] ?? null) : null;
        $barangayId = ($cityId && ! empty($components['sublocality']))
            ? $this->matchBarangay($cityId, $components['sublocality'])
            : null;

        DB::table('properties')->where('id', $property->id)->update([
            'geo_city_id'     => $cityId,
            'geo_barangay_id' => $barangayId,
            'geo_geocoded_at' => now(),
        ]);

        return true;
    }

    /** Resolve by id (loads the row first). */
    public function resolveById(int $propertyId, bool $force = false): bool
    {
        $p = DB::table('properties')->where('id', $propertyId)
            ->first(['id', 'geo_coordinates', 'geo_geocoded_at']);

        return $p ? $this->resolve($p, $force) : false;
    }

    private function parseCoords($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $geo = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($geo)) {
            return null;
        }
        $lat = $geo['lat'] ?? null;
        $lng = $geo['lng'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        // Philippines bounding box guard.
        if ($lat < 4 || $lat > 21 || $lng < 116 || $lng > 127) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function normalize(?string $s): string
    {
        if ($s === null) {
            return '';
        }
        $s = mb_strtolower($s);
        $s = preg_replace('/\bcity of\b/', ' ', $s);
        $s = preg_replace('/\bmunicipality of\b/', ' ', $s);
        $s = preg_replace('/\bcity\b/', ' ', $s);
        $s = preg_replace('/[^a-z\s]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    private function cityIndex(): array
    {
        if ($this->cityIndex === null) {
            $this->cityIndex = [];
            $rows = DB::table('cities')
                ->leftJoin('provinces', 'provinces.id', '=', 'cities.province_id')
                ->get(['cities.id as id', 'cities.name as name', 'provinces.name as province']);
            foreach ($rows as $r) {
                $key = $this->normalize($r->name);
                if ($key === '') {
                    continue;
                }
                $this->cityIndex[$key][] = ['id' => (int) $r->id, 'province' => $this->normalize($r->province)];
            }
        }

        return $this->cityIndex;
    }

    private function matchCity(?string $locality, ?string $provinceHint): ?int
    {
        $key = $this->normalize($locality);
        if ($key === '') {
            return null;
        }
        $candidates = $this->cityIndex()[$key] ?? null;
        if (! $candidates) {
            return null;
        }
        if (count($candidates) === 1) {
            return $candidates[0]['id'];
        }
        // Disambiguate duplicate city names by the geocoded province.
        $pn = $this->normalize($provinceHint);
        if ($pn !== '') {
            foreach ($candidates as $c) {
                if ($c['province'] !== '' && ($c['province'] === $pn || str_contains($pn, $c['province']) || str_contains($c['province'], $pn))) {
                    return $c['id'];
                }
            }
        }

        return $candidates[0]['id'];
    }

    private function matchBarangay(int $cityId, string $sublocality): ?int
    {
        $key = $this->normalize($sublocality);
        if ($key === '') {
            return null;
        }
        $rows = DB::table('barangays')->where('city_id', $cityId)->get(['id', 'name']);
        foreach ($rows as $r) {
            if ($this->normalize($r->name) === $key) {
                return (int) $r->id;
            }
        }

        return null;
    }
}
