<?php

namespace App\Services\Listing;

use App\Support\ProvinceCanonicalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Geometry side of the Listings Heatmap: one GeoJSON FeatureCollection per
 * (level, province scope), cached for a day as an ALREADY-ENCODED string.
 *
 * Caching the string rather than the array is the whole point. The nationwide
 * city layer is ~940 KB of coordinates; decoding it into PHP arrays on every
 * cache hit only to re-encode it byte-for-byte would cost more than the SQL it
 * replaced. The polygon JSON MySQL hands back is therefore spliced into the
 * response verbatim and never parsed.
 *
 * Invalidation rides on `heatmap:boundaries:ver`, bumped by
 * `boundaries:import` and `boundaries:relink-cities`. The cache key embeds that
 * version instead of being flushed, because the production cache driver is the
 * file store, which has no tags — and because an old generation expiring on its
 * own is harmless while a failed flush is not.
 */
class HeatmapBoundaryService
{
    private const TTL = 86400;

    public function __construct(private BoundaryGeoJsonRepository $repository) {}

    /**
     * @param  'province'|'city'  $level
     * @param  int|null  $provinceId  canonical or not; only meaningful at city level
     * @return array{json: string, etag: string, version: int, level: string, province_id: int|null}
     */
    public function payload(string $level, ?int $provinceId = null): array
    {
        $level = $level === 'city' ? 'city' : 'province';

        $provinceNames = DB::table('provinces')->pluck('name', 'id')->all();
        $canonicalProvinceId = $provinceId !== null
            ? (ProvinceCanonicalizer::idMap($provinceNames)[$provinceId] ?? $provinceId)
            : null;

        // The province layer is always the whole country — a "province scope"
        // there would cache the same 80 polygons under 80 different keys.
        $scopedToProvince = $level === 'city' && $canonicalProvinceId !== null;

        $groupIds = $scopedToProvince
            ? ProvinceCanonicalizer::groupIds($provinceNames, $provinceId)
            : [];

        $version = (int) Cache::get('heatmap:boundaries:ver', 1);
        $scopeKey = $scopedToProvince ? (string) $canonicalProvinceId : 'all';

        $json = Cache::remember(
            "heatmap:boundaries:v{$version}:{$level}:{$scopeKey}",
            self::TTL,
            fn () => $this->build($level, $groupIds)
        );

        return [
            'json' => $json,
            // Strong validator: the body is a pure function of the cached
            // string, so byte-identical content always yields the same tag.
            'etag' => '"'.md5($json).'"',
            'version' => $version,
            'level' => $level,
            'province_id' => $canonicalProvinceId,
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
    private function build(string $level, array $provinceIds): string
    {
        $features = [];

        foreach ($this->repository->features($level, $provinceIds) as $row) {
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
