<?php

namespace App\Services\Reports\Executive;

use App\Support\CityGroup;
use App\Support\ProvinceCanonicalizer;
use App\Support\RegionMap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Places, folded the way the Listings Heatmap folds them, so a city or a
 * region means the same thing in the report as on the map.
 *
 * The `provinces` and `cities` tables carry duplicate rows for one real place
 * (Samar's 82/83, two "Carmen" in Cebu, …). Every grouping in the report runs
 * on RAW ids in SQL and is folded here in PHP, and every drill filter expands
 * back to the whole group of raw ids — so a city row and the list behind it
 * always count the same listings, whichever duplicate a listing was filed under.
 *
 *   - province → its canonical id via {@see ProvinceCanonicalizer} (82 → 83);
 *   - region   → {@see RegionMap::regionOf()} of the CANONICAL name. Looking up
 *                the raw name would drop "Southern Samar" (82), which maps to no
 *                region on its own;
 *   - city     → {@see CityGroup::key()} (canonical province + normalized name),
 *                one group per town, identified by its lowest id. The heatmap
 *                accepts any id of a group, so a link built from it lands right.
 *
 * Loaded once (≈83 provinces, ≈1,650 cities) and cached for an hour — the
 * tables only change on an import.
 */
final class Geography
{
    public const UNKNOWN_REGION = 'unknown';

    /** @var array{provinces: array<int,string>, canonical: array<int,int>, cityGroupOf: array<int,int>, cityGroups: array<int,int[]>, cityNames: array<int,string>, cityProvince: array<int,int|null>}|null */
    private ?array $data = null;

    /** @return array<int, string> raw province id => name */
    public function provinceNames(): array
    {
        return $this->data()['provinces'];
    }

    /** The id a province is reported under (82 → 83); null stays null. */
    public function canonicalProvince(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return $this->data()['canonical'][$id] ?? $id;
    }

    /** The canonical province's name. */
    public function provinceName(?int $id): ?string
    {
        $canonical = $this->canonicalProvince($id);

        return $canonical === null ? null : ($this->data()['provinces'][$canonical] ?? null);
    }

    /** Every raw province id that is the same place as $id, ascending. */
    public function provinceGroupIds(int $id): array
    {
        $canonical = $this->canonicalProvince($id);
        $ids = [];
        foreach ($this->data()['canonical'] as $raw => $c) {
            if ($c === $canonical) {
                $ids[] = (int) $raw;
            }
        }

        return $ids === [] ? [$id] : $ids;
    }

    /** PSA region key of a raw province id, or null when it maps to none. */
    public function regionOfProvince(?int $id): ?string
    {
        return RegionMap::regionOf($this->provinceName($id));
    }

    /**
     * Raw province ids in a region (every duplicate row included). `unknown`
     * is not handled here — it is "no province OR no region", which needs a
     * NULL check the caller adds (see ReportQueries::applyFilters()).
     */
    public function provinceIdsForRegion(string $region): array
    {
        $ids = [];
        foreach (array_keys($this->data()['provinces']) as $raw) {
            if ($this->regionOfProvince((int) $raw) === $region) {
                $ids[] = (int) $raw;
            }
        }

        return $ids;
    }

    /** Raw province ids on an island (luzon | visayas | mindanao). */
    public function provinceIdsForIsland(string $island): array
    {
        $ids = [];
        foreach (array_keys($this->data()['provinces']) as $raw) {
            $region = $this->regionOfProvince((int) $raw);
            if ($region !== null && RegionMap::islandOf($region) === $island) {
                $ids[] = (int) $raw;
            }
        }

        return $ids;
    }

    /** Raw province ids that map to no PSA region (part of "unknown"). */
    public function unmappedProvinceIds(): array
    {
        $ids = [];
        foreach (array_keys($this->data()['provinces']) as $raw) {
            if ($this->regionOfProvince((int) $raw) === null) {
                $ids[] = (int) $raw;
            }
        }

        return $ids;
    }

    /** The group a raw city id belongs to (its town's lowest id); null stays null. */
    public function cityGroup(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return $this->data()['cityGroupOf'][$id] ?? $id;
    }

    /** Every raw city id of the town $id belongs to (any id of the group works). */
    public function cityGroupIds(int $id): array
    {
        $group = $this->cityGroup($id);

        return $this->data()['cityGroups'][$group] ?? [$id];
    }

    public function cityName(?int $groupId): ?string
    {
        return $groupId === null ? null : ($this->data()['cityNames'][$groupId] ?? null);
    }

    /** Canonical province id of a city group. */
    public function cityProvince(?int $groupId): ?int
    {
        return $groupId === null ? null : ($this->data()['cityProvince'][$groupId] ?? null);
    }

    private function data(): array
    {
        return $this->data ??= Cache::remember('exec-report:v1:geography', 3600, function () {
            $provinces = DB::table('provinces')->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])->all();
            $canonical = ProvinceCanonicalizer::idMap($provinces);

            $keyMembers = [];
            $keyName = [];
            $keyProvince = [];
            foreach (DB::table('cities')->orderBy('id')->get(['id', 'name', 'province_id']) as $city) {
                $province = $city->province_id !== null ? ($canonical[(int) $city->province_id] ?? (int) $city->province_id) : null;
                $key = CityGroup::key($province, (string) $city->name);
                $keyMembers[$key][] = (int) $city->id;
                // Rows come ordered by id, so the first name seen is the group id's own.
                $keyName[$key] ??= (string) $city->name;
                $keyProvince[$key] ??= $province;
            }

            $cityGroupOf = [];
            $cityGroups = [];
            $cityNames = [];
            $cityProvince = [];
            foreach ($keyMembers as $key => $ids) {
                $group = min($ids);
                $cityGroups[$group] = $ids;
                $cityNames[$group] = $keyName[$key];
                $cityProvince[$group] = $keyProvince[$key];
                foreach ($ids as $id) {
                    $cityGroupOf[$id] = $group;
                }
            }

            return compact('provinces', 'canonical', 'cityGroupOf', 'cityGroups', 'cityNames', 'cityProvince');
        });
    }
}
