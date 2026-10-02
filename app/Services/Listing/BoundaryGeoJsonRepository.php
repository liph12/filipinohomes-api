<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * The ONE place the Listings Heatmap touches spatial SQL.
 *
 * Everything else in the heatmap stack is dialect-neutral so it can be tested
 * on sqlite, which has no ST_* functions at all. Rather than litter the service
 * with "skip this on sqlite" branches, every ST_ call is fenced in here and the
 * feature test binds a fake instance of this class in the container.
 *
 * Simplification is not cosmetic. The raw ADM2 province layer is ~3 MB and
 * MySQL's default ST_AsGeoJSON precision (17 significant digits) inflates the
 * nationwide city layer to ~6 MB — a payload that size would stall the map on
 * the admin's connection long before it ever drew a colour. The tolerance /
 * decimal-digit pairs below were measured against the real data:
 *
 *   province, nationwide      0.004  / 4 dp  ≈ 360 KB raw, 110 KB gzipped
 *   city, nationwide          0.004  / 4 dp  ≈ 940 KB raw, 263 KB gzipped
 *   city, inside one province 0.0012 / 5 dp  — finer, because one province's
 *                                              towns fill the screen and the
 *                                              coarse tolerance shows corners
 *   barangay, inside one city   none / 5 dp  — see below
 *
 * ST_Simplify returns NULL when it cannot simplify a geometry (self-touching
 * rings, degenerate slivers), so every call is wrapped in COALESCE back to the
 * original geometry: a heavier polygon beats a hole in the map.
 *
 * The barangay layer is the one level with NO ST_Simplify call at all, and
 * that is deliberate twice over. Those polygons were already simplified
 * offline by a topology-aware pass (shared borders are one arc, so neighbours
 * never gap or overlap); simplifying each polygon again in isolation would
 * reopen exactly the slivers that pass closed. And MySQL errors outright on a
 * tolerance of 0, so "simplify with a no-op tolerance" is not available as a
 * uniform code path.
 */
class BoundaryGeoJsonRepository
{
    private const TOLERANCE_PROVINCE = 0.004;

    private const DIGITS_PROVINCE = 4;

    private const TOLERANCE_CITY_NATIONWIDE = 0.004;

    private const DIGITS_CITY_NATIONWIDE = 4;

    private const TOLERANCE_CITY_IN_PROVINCE = 0.0012;

    private const DIGITS_CITY_IN_PROVINCE = 5;

    /** Barangay geometry ships at the precision it was simplified to offline. */
    private const DIGITS_BARANGAY = 5;

    /**
     * Simplified polygons for one level, optionally narrowed to one province
     * group (city level) or one city group (barangay level).
     *
     * Unlinked polygons (no `city_id` / `province_id` / `barangay_id`) are
     * returned too, with a null `area_id`. They carry no count and render as
     * "no location linked", but leaving them out would punch holes in the land
     * — and a hole reads as "nothing here", which is a different and wrong
     * claim. At barangay level those are the registry gaps: real PSA barangays
     * the `barangays` table has never heard of.
     *
     * @param  'province'|'city'|'barangay'  $level
     * @param  int[]  $provinceIds  empty = nationwide (ignored at province and barangay level)
     * @param  int[]  $cityIds  barangay level only; empty = no rows, deliberately
     * @return array<int, array{boundary_id: int, area_id: int|null, name: string, geojson: ?string, centroid: ?string}>
     */
    public function features(string $level, array $provinceIds = [], array $cityIds = []): array
    {
        $level = in_array($level, ['city', 'barangay'], true) ? $level : 'province';

        if ($level === 'barangay') {
            $ids = array_values(array_unique(array_map('intval', $cityIds)));

            // No city group, no layer. A nationwide barangay query would be
            // 42k polygons and hundreds of megabytes of coordinates — the one
            // request this endpoint must never be able to make by accident.
            if ($ids === []) {
                return [];
            }

            $sql = 'SELECT boundaries.id AS boundary_id,
                           boundaries.barangay_id AS area_id,
                           boundaries.name AS name,
                           ST_AsGeoJSON(boundaries.geom, ?) AS geojson,
                           ST_AsGeoJSON(ST_Centroid(boundaries.geom)) AS centroid
                    FROM boundaries
                    WHERE boundaries.level = ?
                      AND boundaries.city_id IN ('.implode(',', array_fill(0, count($ids), '?')).')';

            $bindings = array_merge([self::DIGITS_BARANGAY, 'barangay'], $ids);

            return $this->rows($sql.' ORDER BY boundaries.id', $bindings);
        }

        $scoped = $level === 'city' && $provinceIds !== [];

        if ($level === 'province') {
            $idColumn = 'province_id';
            $tolerance = self::TOLERANCE_PROVINCE;
            $digits = self::DIGITS_PROVINCE;
        } else {
            $idColumn = 'city_id';
            $tolerance = $scoped ? self::TOLERANCE_CITY_IN_PROVINCE : self::TOLERANCE_CITY_NATIONWIDE;
            $digits = $scoped ? self::DIGITS_CITY_IN_PROVINCE : self::DIGITS_CITY_NATIONWIDE;
        }

        $sql = "SELECT boundaries.id AS boundary_id,
                       boundaries.{$idColumn} AS area_id,
                       boundaries.name AS name,
                       ST_AsGeoJSON(COALESCE(ST_Simplify(boundaries.geom, ?), boundaries.geom), ?) AS geojson,
                       ST_AsGeoJSON(ST_Centroid(boundaries.geom)) AS centroid
                FROM boundaries
                WHERE boundaries.level = ?";

        $bindings = [$tolerance, $digits, $level];

        if ($scoped) {
            $ids = array_values(array_unique(array_map('intval', $provinceIds)));
            $sql .= ' AND boundaries.province_id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            $bindings = array_merge($bindings, $ids);
        }

        // Stable order so the cached string (and therefore its ETag) is
        // identical between two runs over unchanged data.
        return $this->rows($sql.' ORDER BY boundaries.id', $bindings);
    }

    /** @return array<int, array{boundary_id: int, area_id: int|null, name: string, geojson: ?string, centroid: ?string}> */
    private function rows(string $sql, array $bindings): array
    {
        return array_map(
            fn ($row) => [
                'boundary_id' => (int) $row->boundary_id,
                'area_id' => $row->area_id !== null ? (int) $row->area_id : null,
                'name' => (string) $row->name,
                'geojson' => $row->geojson !== null ? (string) $row->geojson : null,
                'centroid' => $row->centroid !== null ? (string) $row->centroid : null,
            ],
            DB::select($sql, $bindings)
        );
    }
}
