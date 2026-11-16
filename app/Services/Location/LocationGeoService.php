<?php

namespace App\Services\Location;

use Illuminate\Support\Facades\Cache;

/**
 * Where a picked province / city / barangay is — from our own `boundaries` shapes, by ID —
 * so the location pickers frame it without geocoding (no Google bill, no Nominatim wait, and
 * no "Cebu" vs "Cebu City" or many-"Poblacion" mix-ups, since it's by ID, not by name).
 *
 *  - barangay: its own shape;
 *  - city:     its own shape, else the box around its barangays' shapes;
 *  - province: its own shape, else the box around its cities' shapes (centre = middle of that box).
 *
 * Answers are cached a day (shapes rarely change). null = no shape for it → the frontend falls
 * back to geocoding the name.
 *
 * @phpstan-import-type Envelope from BoundaryEnvelopeRepository
 */
class LocationGeoService
{
    private const TTL_SECONDS = 86400;

    public function __construct(private BoundaryEnvelopeRepository $boundaries) {}

    /** @return array{lat: float, lng: float, bounds: array{north: float, south: float, east: float, west: float}}|null */
    public function barangay(int $id): ?array
    {
        return $this->cached("barangay:$id", fn () => $this->shape($this->boundaries->barangay($id)));
    }

    /** @return array{lat: float, lng: float, bounds: array{north: float, south: float, east: float, west: float}}|null */
    public function city(int $id): ?array
    {
        return $this->cached("city:$id", fn () => $this->shape($this->boundaries->city($id))
            ?? $this->around($this->boundaries->barangaysOfCity($id)));
    }

    /** @return array{lat: float, lng: float, bounds: array{north: float, south: float, east: float, west: float}}|null */
    public function province(int $id): ?array
    {
        return $this->cached("province:$id", fn () => $this->shape($this->boundaries->province($id))
            ?? $this->around($this->boundaries->citiesOfProvince($id)));
    }

    /** @param  Envelope|null  $env */
    private function shape(?array $env): ?array
    {
        if (! $env) {
            return null;
        }

        return [
            'lat' => round($env['lat'], 7),
            'lng' => round($env['lng'], 7),
            'bounds' => $this->box($env),
        ];
    }

    /**
     * One box around several shapes; centre = the middle of that box.
     *
     * @param  list<Envelope>  $envs
     */
    private function around(array $envs): ?array
    {
        if ($envs === []) {
            return null;
        }
        $box = [
            'north' => max(array_column($envs, 'north')),
            'south' => min(array_column($envs, 'south')),
            'east' => max(array_column($envs, 'east')),
            'west' => min(array_column($envs, 'west')),
        ];

        return [
            'lat' => round(($box['north'] + $box['south']) / 2, 7),
            'lng' => round(($box['east'] + $box['west']) / 2, 7),
            'bounds' => $this->box($box),
        ];
    }

    private function box(array $b): array
    {
        return [
            'north' => round($b['north'], 7),
            'south' => round($b['south'], 7),
            'east' => round($b['east'], 7),
            'west' => round($b['west'], 7),
        ];
    }

    private function cached(string $key, callable $compute): ?array
    {
        // Wrapped so a cached "no shape" (null) is told apart from "not cached yet".
        $hit = Cache::remember("location-geo:v1:$key", self::TTL_SECONDS, fn () => ['value' => $compute()]);

        return $hit['value'];
    }
}
