<?php

namespace App\Support;

/**
 * ONE definition of "the same town" for every heatmap surface.
 *
 * The `cities` table carries duplicate rows for the same town inside one
 * province (11 same-name pairs today — two "Carmen" in Cebu, two
 * "Catbalogan" in Samar's duplicate province rows, …), and a polygon can only
 * ever point at one of them. The counts service folds those rows into one
 * bucket by (canonical province, normalized name); the barangay drill must
 * scope its query and its geometry to the WHOLE group, or the barangay rows
 * of a twin city would not sum to the city row the user just clicked.
 *
 * Before this class, that fold key lived privately in the heatmap counts
 * service (ListingHeatmapService::cityKey()) and the "which id survives" rule
 * in its offerCityId(). Both now live here so the counts path, the geometry
 * path and the controller's cache key agree by construction.
 *
 * Pure PHP: the caller passes the `cities` rows it already loaded.
 */
class CityGroup
{
    /**
     * Fold key for a city: one canonical province + one normalized town name.
     *
     * $canonicalProvince is the province id after {@see ProvinceCanonicalizer}
     * (so Samar's 82 and 83 share a key); null — a city with no province —
     * keys under "none" rather than colliding with province 0.
     */
    public static function key(int|string|null $canonicalProvince, string $cityName): string
    {
        return ($canonicalProvince ?? 'none').'|'.CityNameMatcher::normalize($cityName);
    }

    /**
     * Every `cities` id that is the same town as $cityId, ascending.
     *
     * @param  iterable<array|object>  $cities  rows with id, name, province_id (arrays or objects)
     * @param  array<int, int>  $provinceIdMap  ProvinceCanonicalizer::idMap() of the provinces table
     * @param  int  $cityId  the requested row; an id not in $cities returns just itself
     * @return int[]
     */
    public static function groupIds(iterable $cities, array $provinceIdMap, int $cityId): array
    {
        $keys = [];
        foreach ($cities as $row) {
            $id = (int) self::field($row, 'id');
            $provinceId = self::field($row, 'province_id');
            $provinceId = $provinceId !== null ? (int) $provinceId : null;
            $canonical = $provinceId !== null ? ($provinceIdMap[$provinceId] ?? $provinceId) : null;

            $keys[$id] = self::key($canonical, (string) self::field($row, 'name'));
        }

        if (! isset($keys[$cityId])) {
            return [$cityId];
        }

        $wanted = $keys[$cityId];
        $ids = [];
        foreach ($keys as $id => $key) {
            if ($key === $wanted) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * The id a group is painted and cached under: the lowest id that owns a
     * polygon, else the lowest id. Exactly the survivor offerCityId() picks,
     * so the row the map can shade is the row that holds the count — and two
     * twins requested separately resolve to one cache key.
     *
     * @param  int[]  $groupIds  from groupIds(); must not be empty
     * @param  array<int, true>|int[]  $linkedCityIds  ids that own a polygon, as a set or a list
     */
    public static function canonicalId(array $groupIds, array $linkedCityIds): int
    {
        $linked = array_is_list($linkedCityIds)
            ? array_fill_keys(array_map('intval', $linkedCityIds), true)
            : $linkedCityIds;

        $ids = array_map('intval', $groupIds);
        sort($ids);

        foreach ($ids as $id) {
            if (isset($linked[$id])) {
                return $id;
            }
        }

        return $ids[0];
    }

    private static function field(array|object $row, string $name): mixed
    {
        return is_array($row) ? ($row[$name] ?? null) : ($row->{$name} ?? null);
    }
}
