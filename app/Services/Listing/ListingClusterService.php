<?php

namespace App\Services\Listing;

use App\Support\IslandMap;
use App\Support\RegionMap;
use Illuminate\Support\Facades\DB;

/**
 * Listing Insights — geo clusters for the shared hierarchical map. Returns
 * count-weighted centroid bubbles for the CURRENT drill level (island → region
 * → province → city → barangay), each clickable to descend. Counterpart to
 * InquiryHeatmapService::clusters(), but rooted on listings (not chats) and
 * scoped by the same filters the insight tabs use (configure()).
 *
 * Upper levels (island/region/province) render as bubbles; the frontend draws
 * city/barangay as clickable boundary polygons, but this service still answers
 * those levels (bubbles) as a fallback when polygon data is unavailable.
 */
class ListingClusterService extends ListingInsightsService
{
    // Location resolution expressions (project path, else property → barangay →
    // city → province). Mirrors ListingByProvinceService — NO geo-first join, so
    // it stays consistent with the By Province tab's counts.
    private function provinceIdExpr(): string
    {
        return 'COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)';
    }

    private function provinceNameExpr(): string
    {
        return 'COALESCE(project_provinces.name, property_provinces.name)';
    }

    private function cityIdExpr(): string
    {
        return 'COALESCE(projects.city_id, property_cities.id)';
    }

    private function cityNameExpr(): string
    {
        return 'COALESCE(project_cities.name, property_cities.name)';
    }

    private function barangayIdExpr(): string
    {
        return 'properties.address_id';
    }

    private function barangayNameExpr(): string
    {
        return 'barangays.name';
    }

    // Count-weighted centroid restricted to the Philippines bounding box
    // (lat 4–21, lng 116–127) so junk / out-of-country pins can't drag the
    // average offshore. AVG ignores the CASE's NULLs; COUNT still reflects every
    // listing in the group.
    private function latExpr(): string
    {
        $lat = $this->geoLat();
        $lng = $this->geoLng();

        return "AVG(CASE WHEN ({$lat}) BETWEEN 4 AND 21 AND ({$lng}) BETWEEN 116 AND 127 THEN ({$lat}) END)";
    }

    private function lngExpr(): string
    {
        $lat = $this->geoLat();
        $lng = $this->geoLng();

        return "AVG(CASE WHEN ({$lat}) BETWEEN 4 AND 21 AND ({$lng}) BETWEEN 116 AND 127 THEN ({$lng}) END)";
    }

    /** Aggregated clickable clusters for the current drill level. */
    public function clusters(): array
    {
        $level = $this->resolveLevel();

        return match ($level) {
            'island' => $this->groupedClusters('island'),
            'region' => $this->groupedClusters('region'),
            default => $this->entityClusters($level),
        };
    }

    private function resolveLevel(): string
    {
        if (in_array($this->levelOverride, ['island', 'region', 'province', 'city', 'barangay'], true)) {
            return $this->levelOverride;
        }
        if ($this->cityId) {
            return 'barangay';
        }
        if ($this->provinceId) {
            return 'city';
        }
        if ($this->region) {
            return 'province';
        }
        if ($this->island) {
            return 'region';
        }

        return 'island';
    }

    private function validPoint(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) {
            return false;
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return false;
        }
        if ($lat === 0.0 && $lng === 0.0) {
            return false;
        }

        return true;
    }

    private function categorySelects(): array
    {
        return [
            DB::raw("SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale"),
            DB::raw("SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent"),
            DB::raw("SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure"),
        ];
    }

    /**
     * Island- or region-level clusters: group by province, then fold provinces
     * into the island/region (count-weighted centroid mean) in PHP.
     */
    private function groupedClusters(string $level): array
    {
        $p = $this->provinceIdExpr();
        $pn = $this->provinceNameExpr();

        $rows = $this->baseListingQuery()
            ->groupBy(DB::raw($p), DB::raw($pn))
            ->get(array_merge([
                DB::raw("{$p} as province_id"),
                DB::raw("{$pn} as province_name"),
                DB::raw('COUNT(listings.id) as c'),
                DB::raw("{$this->latExpr()} as lat"),
                DB::raw("{$this->lngExpr()} as lng"),
            ], $this->categorySelects()));

        // acc[key] = [count, latSum, lngSum, wSum, for_sale, for_rent, foreclosure]
        $acc = [];
        foreach ($rows as $r) {
            $key = $level === 'region'
                ? RegionMap::regionOf($r->province_name)
                : IslandMap::islandOf($r->province_name);
            if ($key === null) {
                continue;
            } // unclassified province → no bubble

            if (! isset($acc[$key])) {
                $acc[$key] = ['count' => 0, 'latSum' => 0.0, 'lngSum' => 0.0, 'wSum' => 0, 'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            }
            $count = (int) $r->c;
            $acc[$key]['count'] += $count;
            $acc[$key]['for_sale'] += (int) $r->for_sale;
            $acc[$key]['for_rent'] += (int) $r->for_rent;
            $acc[$key]['foreclosure'] += (int) $r->foreclosure;

            $lat = $r->lat !== null ? (float) $r->lat : null;
            $lng = $r->lng !== null ? (float) $r->lng : null;
            if ($this->validPoint($lat, $lng)) {
                $acc[$key]['latSum'] += $lat * $count;
                $acc[$key]['lngSum'] += $lng * $count;
                $acc[$key]['wSum'] += $count;
            }
        }

        $order = $level === 'region' ? RegionMap::REGIONS : IslandMap::ISLANDS;
        $clusters = [];
        foreach ($order as $key) {
            if (! isset($acc[$key]) || $acc[$key]['wSum'] === 0) {
                continue;
            }
            $a = $acc[$key];
            $clusters[] = [
                'level' => $level,
                'key' => $key,
                'id' => null,
                'name' => $level === 'region' ? RegionMap::label($key) : IslandMap::label($key),
                'island' => $level === 'region' ? RegionMap::islandOf($key) : null,
                'lat' => $a['latSum'] / $a['wSum'],
                'lng' => $a['lngSum'] / $a['wSum'],
                'count' => $a['count'],
                'by_category' => ['for_sale' => $a['for_sale'], 'for_rent' => $a['for_rent'], 'foreclosure' => $a['foreclosure']],
                'drillable' => true,
            ];
        }

        return $this->wrap($clusters, $level);
    }

    private function entityClusters(string $level): array
    {
        [$idExpr, $nameExpr] = match ($level) {
            'province' => [$this->provinceIdExpr(), $this->provinceNameExpr()],
            'city' => [$this->cityIdExpr(), $this->cityNameExpr()],
            'barangay' => [$this->barangayIdExpr(), $this->barangayNameExpr()],
        };

        $rows = $this->baseListingQuery()
            ->groupBy(DB::raw($idExpr), DB::raw($nameExpr))
            ->get(array_merge([
                DB::raw("{$idExpr} as gid"),
                DB::raw("{$nameExpr} as gname"),
                DB::raw('COUNT(listings.id) as c'),
                DB::raw("{$this->latExpr()} as lat"),
                DB::raw("{$this->lngExpr()} as lng"),
            ], $this->categorySelects()));

        $clusters = [];
        foreach ($rows as $r) {
            $lat = $r->lat !== null ? (float) $r->lat : null;
            $lng = $r->lng !== null ? (float) $r->lng : null;
            if (! $this->validPoint($lat, $lng)) {
                continue;
            } // unmappable group → no bubble

            $clusters[] = [
                'level' => $level,
                'key' => null,
                'id' => $r->gid !== null ? (int) $r->gid : null,
                'name' => (string) ($r->gname ?? 'Unknown'),
                'island' => null,
                'lat' => $lat,
                'lng' => $lng,
                'count' => (int) $r->c,
                'by_category' => ['for_sale' => (int) $r->for_sale, 'for_rent' => (int) $r->for_rent, 'foreclosure' => (int) $r->foreclosure],
                'drillable' => $level !== 'barangay' && $r->gid !== null,
            ];
        }

        return $this->wrap($clusters, $level);
    }

    private function wrap(array $clusters, string $level): array
    {
        usort($clusters, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'data' => $clusters,
            'totals' => [
                'clusters' => count($clusters),
                'max_count' => $clusters ? max(array_column($clusters, 'count')) : 0,
            ],
            'meta' => [
                'level' => $level,
                'date_start' => $this->dateStart,
                'date_end' => $this->dateEnd,
                'island' => $this->island,
                'region' => $this->region,
                'province_id' => $this->provinceId,
                'city_id' => $this->cityId,
                'barangay_id' => $this->barangayId,
            ],
        ];
    }
}
