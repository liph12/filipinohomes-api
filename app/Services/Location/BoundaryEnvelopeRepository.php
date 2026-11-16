<?php

namespace App\Services\Location;

use Illuminate\Support\Facades\DB;

/**
 * Reads where a city / barangay is from the `boundaries` polygons (PSA/NAMRIA shapes, linked by
 * city_id / barangay_id — the same data the listings heatmap draws): each shape's bounding box
 * and centroid. MySQL spatial SQL lives only here, so LocationGeoService stays testable.
 *
 * Geometry is SRID 0 with coordinates in (lng lat) order, as everywhere else on `boundaries`.
 *
 * @phpstan-type Envelope array{north: float, south: float, east: float, west: float, lat: float, lng: float}
 */
class BoundaryEnvelopeRepository
{
    /** @return Envelope|null */
    public function barangay(int $barangayId): ?array
    {
        return $this->first("level = 'barangay' AND barangay_id = ?", [$barangayId]);
    }

    /** @return Envelope|null */
    public function city(int $cityId): ?array
    {
        return $this->first("level = 'city' AND city_id = ?", [$cityId]);
    }

    /** @return Envelope|null */
    public function province(int $provinceId): ?array
    {
        return $this->first("level = 'province' AND province_id = ?", [$provinceId]);
    }

    /** @return list<Envelope> every barangay shape inside a city */
    public function barangaysOfCity(int $cityId): array
    {
        return $this->all("level = 'barangay' AND city_id = ?", [$cityId]);
    }

    /** @return list<Envelope> every city shape of a province (via cities.province_id) */
    public function citiesOfProvince(int $provinceId): array
    {
        return $this->all(
            "level = 'city' AND city_id IN (SELECT id FROM cities WHERE province_id = ?)",
            [$provinceId],
        );
    }

    /** @return Envelope|null */
    private function first(string $where, array $bindings): ?array
    {
        return $this->all($where, $bindings, 1)[0] ?? null;
    }

    /** @return list<Envelope> */
    private function all(string $where, array $bindings, ?int $limit = null): array
    {
        $rows = DB::select(
            'SELECT ST_AsGeoJSON(ST_Envelope(geom)) AS box, ST_X(ST_Centroid(geom)) AS cx, ST_Y(ST_Centroid(geom)) AS cy
             FROM boundaries WHERE '.$where.($limit ? ' LIMIT '.(int) $limit : ''),
            $bindings,
        );

        $out = [];
        foreach ($rows as $row) {
            $env = self::parse($row->box, $row->cx, $row->cy);
            if ($env) {
                $out[] = $env;
            }
        }

        return $out;
    }

    /**
     * The envelope's GeoJSON → a box (+ centroid). An envelope is a Polygon, or a Point/LineString
     * for a degenerate shape.
     *
     * @return Envelope|null
     */
    public static function parse(?string $geojson, mixed $cx, mixed $cy): ?array
    {
        $g = json_decode((string) $geojson, true);
        $points = [];
        $walk = function ($c) use (&$walk, &$points) {
            if (is_array($c) && count($c) >= 2 && is_numeric($c[0]) && is_numeric($c[1])) {
                $points[] = [(float) $c[0], (float) $c[1]]; // [lng, lat]

                return;
            }
            foreach ((array) $c as $child) {
                $walk($child);
            }
        };
        $walk($g['coordinates'] ?? []);
        if ($points === []) {
            return null;
        }

        $lngs = array_column($points, 0);
        $lats = array_column($points, 1);
        $box = ['north' => max($lats), 'south' => min($lats), 'east' => max($lngs), 'west' => min($lngs)];

        return $box + [
            'lat' => is_numeric($cy) ? (float) $cy : ($box['north'] + $box['south']) / 2,
            'lng' => is_numeric($cx) ? (float) $cx : ($box['east'] + $box['west']) / 2,
        ];
    }
}
