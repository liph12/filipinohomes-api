<?php

namespace App\Services\Listing;

use App\Support\ProvinceCanonicalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Geometry side of the Listings Heatmap: one GeoJSON FeatureCollection per
 * (level, scope), cached for a day as an ALREADY-ENCODED string. The scope is
 * a province group at city level and a city group at barangay level.
 *
 * Caching the string rather than the array is the whole point. The nationwide
 * city layer is ~940 KB of coordinates; decoding it into PHP arrays on every
 * cache hit only to re-encode it byte-for-byte would cost more than the SQL it
 * replaced. The polygon JSON MySQL hands back is therefore spliced into the
 * response verbatim and never parsed.
 *
 * Invalidation rides on `heatmap:boundaries:ver`, bumped by
 * `boundaries:import`, `boundaries:relink-cities` and
 * `boundaries:relink-barangays`. The cache key embeds that version instead of
 * being flushed, because the production cache driver is the file store, which
 * has no tags — and because an old generation expiring on its own is harmless
 * while a failed flush is not.
 *
 * The city group is resolved through {@see ListingHeatmapService} rather than
 * recomputed here: the barangay polygons of a town and the barangay COUNTS of
 * that town have to agree on which `cities` rows are the same place, or the
 * map would shade a group the table never totals.
 */
class HeatmapBoundaryService
{
    private const TTL = 86400;

    public function __construct(
        private BoundaryGeoJsonRepository $repository,
        private ListingHeatmapService $heatmap,
    ) {}

    /**
     * @param  'province'|'city'|'barangay'  $level
     * @param  int|null  $provinceId  canonical or not; only meaningful at city level
     * @param  int|null  $cityId  any id of the city group; REQUIRED at barangay level, ignored elsewhere
     * @return array{json: string, etag: string, version: int, level: string, province_id: int|null, city_id: int|null}
     */
    public function payload(string $level, ?int $provinceId = null, ?int $cityId = null): array
    {
        $level = in_array($level, ListingHeatmapService::LEVELS, true) ? $level : 'province';

        if ($level === 'barangay' && $cityId === null) {
            throw new InvalidArgumentException('The barangay layer needs a city_id.');
        }

        $version = (int) Cache::get('heatmap:boundaries:ver', 1);

        if ($level === 'barangay') {
            // The WHOLE group: a polygon can only point at one of a town's
            // duplicate `cities` rows, so scoping to the requested id alone
            // would drop half of a twin town's barangays off the map.
            $cityIds = $this->heatmap->cityGroupIds($cityId);
            $canonicalCityId = $this->heatmap->canonicalCityId($cityId);

            // Prefixed with 'c' so a city id can never collide with the
            // province id the city layer caches under.
            $scopeKey = 'c'.$canonicalCityId;
            $provinceIds = [];
            $canonicalProvinceId = null;
        } else {
            $provinceNames = DB::table('provinces')->pluck('name', 'id')->all();
            $canonicalProvinceId = $provinceId !== null
                ? (ProvinceCanonicalizer::idMap($provinceNames)[$provinceId] ?? $provinceId)
                : null;

            // The province layer is always the whole country — a "province scope"
            // there would cache the same 80 polygons under 80 different keys.
            $scopedToProvince = $level === 'city' && $canonicalProvinceId !== null;

            $provinceIds = $scopedToProvince
                ? ProvinceCanonicalizer::groupIds($provinceNames, $provinceId)
                : [];

            $scopeKey = $scopedToProvince ? (string) $canonicalProvinceId : 'all';
            $cityIds = [];
            $canonicalCityId = null;
        }

        $json = Cache::remember(
            "heatmap:boundaries:v{$version}:{$level}:{$scopeKey}",
            self::TTL,
            fn () => $this->build($level, $provinceIds, $cityIds)
        );

        return [
            'json' => $json,
            // Strong validator: the body is a pure function of the cached
            // string, so byte-identical content always yields the same tag.
            'etag' => '"'.md5($json).'"',
            'version' => $version,
            'level' => $level,
            'province_id' => $canonicalProvinceId,
            'city_id' => $canonicalCityId,
        ];
    }

    /**
     * Assemble the FeatureCollection by string concatenation.
     *
     * `$row['geojson']` is already valid JSON straight from ST_AsGeoJSON, so it
     * is interpolated, not re-encoded. Only the small properties object goes
     * through json_encode. A row whose geometry came back NULL is skipped
     * rather than emitted with a null geometry — deck.gl treats a null geometry
     * as a parse error for the whole collection.
     */
    private function build(string $level, array $provinceIds, array $cityIds): string
    {
        $features = [];

        foreach ($this->repository->features($level, $provinceIds, $cityIds) as $row) {
            $geometry = $row['geojson'] ?? null;
            if (! is_string($geometry) || $geometry === '') {
                continue;
            }

            $properties = json_encode([
                'id' => isset($row['area_id']) && $row['area_id'] !== null ? (int) $row['area_id'] : null,
                'boundary_id' => (int) ($row['boundary_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'centroid' => $this->centroid($row['centroid'] ?? null),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $features[] = '{"type":"Feature","properties":'.$properties.',"geometry":'.$geometry.'}';
        }

        return '{"type":"FeatureCollection","features":['.implode(',', $features).']}';
    }

    /**
     * [lng, lat] for the label anchor, from ST_AsGeoJSON(ST_Centroid(geom)).
     *
     * Null when the geometry has no usable centroid; the canvas falls back to
     * the feature's bbox centre, which is wrong for crescent-shaped provinces
     * but better than no label.
     *
     * @return array{0: float, 1: float}|null
     */
    private function centroid(?string $point): ?array
    {
        if ($point === null || $point === '') {
            return null;
        }

        $decoded = json_decode($point, true);
        if (! isset($decoded['coordinates'][0], $decoded['coordinates'][1])) {
            return null;
        }

        return [(float) $decoded['coordinates'][0], (float) $decoded['coordinates'][1]];
    }
}
