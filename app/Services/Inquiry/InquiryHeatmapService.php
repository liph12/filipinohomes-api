<?php

namespace App\Services\Inquiry;

use App\Support\IslandMap;
use Illuminate\Support\Facades\DB;

/**
 * Inquiry Analytics — geo data for the deck.gl heatmap + clickable cluster
 * drill-down. points() returns one raw point per inquired property (for the
 * gradient). clusters() returns aggregated centroids for the CURRENT drill
 * level (island → province → city → barangay), each clickable to descend.
 */
class InquiryHeatmapService extends InquiryInsightsService
{
    // Centroid of the mapped properties in a group, restricted to coordinates
    // inside the Philippines bounding box (lat 4–21, lng 116–127). Project-unit
    // inquiries sometimes carry junk / out-of-country geo that would otherwise
    // drag the average into the ocean. AVG ignores the CASE's NULLs, so only
    // valid PH points contribute. Count (COUNT(*)) still reflects every inquiry
    // in the group. (geoLat/geoLng come from the base service.)
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

    public function points(): array
    {
        // Cap the point set so the gradient can't ship an unbounded payload at
        // scale (one point per inquired property, heaviest first).
        $cap = 2000;
        $rows = $this->baseInquiryQuery()
            ->whereNotNull('properties.geo_coordinates')
            ->where('properties.geo_coordinates', '!=', '')
            ->groupBy('properties.id', 'properties.geo_coordinates')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($cap)
            ->get([
                DB::raw('properties.geo_coordinates as geo'),
                DB::raw('COUNT(*) as c'),
            ]);

        $points = [];
        $maxWeight = 0;
        foreach ($rows as $r) {
            $geo = json_decode((string) $r->geo, true);
            if (!is_array($geo)) {
                continue;
            }
            $lat = $geo['lat'] ?? null;
            $lng = $geo['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }
            // Restrict to the Philippines bounding box — drops (0,0) and
            // out-of-country junk so the gradient isn't anchored offshore.
            $lat = (float) $lat;
            $lng = (float) $lng;
            if ($lat < 4 || $lat > 21 || $lng < 116 || $lng > 127) {
                continue;
            }
            $w = (int) $r->c;
            $points[] = ['lat' => $lat, 'lng' => $lng, 'weight' => $w];
            $maxWeight = max($maxWeight, $w);
        }

        return [
            'data'   => $points,
            'totals' => [
                'points'     => count($points),
                'max_weight' => $maxWeight,
                'capped_at'  => $cap,
            ],
            'meta' => [
                'date_from'     => $this->dateFrom,
                'date_to'       => $this->dateTo,
                'category_id'   => $this->categoryId,
                'property_type' => $this->propertyType,
            ],
        ];
    }

    /**
     * Aggregated clickable clusters for the current drill level. Each cluster
     * carries a centroid (avg of its mapped properties), an inquiry count, and
     * the key needed to drill one level deeper. Clusters with no mapped
     * properties (null centroid) are skipped.
     */
    public function clusters(): array
    {
        $level = $this->resolveLevel();

        return $level === 'island'
            ? $this->islandClusters()
            : $this->entityClusters($level);
    }

    private function resolveLevel(): string
    {
        // Viewport-driven map sends an explicit level (derived from zoom).
        if (in_array($this->levelOverride, ['island', 'province', 'city', 'barangay'], true)) {
            return $this->levelOverride;
        }
        if ($this->cityId)     return 'barangay';
        if ($this->provinceId) return 'city';
        if ($this->island)     return 'province';

        return 'island';
    }

    private function validPoint(?float $lat, ?float $lng): bool
    {
        if ($lat === null || $lng === null) return false;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
        if ($lat === 0.0 && $lng === 0.0) return false;

        return true;
    }

    private function islandClusters(): array
    {
        $p = $this->provinceIdExpr();
        $pn = $this->provinceNameExpr();

        $rows = $this->baseInquiryQuery()
            ->groupBy(DB::raw($p), DB::raw($pn))
            ->get([
                DB::raw("{$p} as province_id"),
                DB::raw("{$pn} as province_name"),
                DB::raw('COUNT(*) as c'),
                DB::raw("{$this->latExpr()} as lat"),
                DB::raw("{$this->lngExpr()} as lng"),
            ]);

        // Fold provinces into islands; centroid = count-weighted mean of
        // province centroids.
        $acc = []; // island => [count, latSum, lngSum, wSum]
        foreach ($rows as $r) {
            $lat = $r->lat !== null ? (float) $r->lat : null;
            $lng = $r->lng !== null ? (float) $r->lng : null;
            $count = (int) $r->c;
            $island = IslandMap::islandOf($r->province_name) ?? 'unclassified';

            if (!isset($acc[$island])) {
                $acc[$island] = ['count' => 0, 'latSum' => 0.0, 'lngSum' => 0.0, 'wSum' => 0];
            }
            $acc[$island]['count'] += $count;
            if ($this->validPoint($lat, $lng)) {
                $acc[$island]['latSum'] += $lat * $count;
                $acc[$island]['lngSum'] += $lng * $count;
                $acc[$island]['wSum'] += $count;
            }
        }

        $clusters = [];
        foreach (['luzon', 'visayas', 'mindanao'] as $key) {
            if (!isset($acc[$key]) || $acc[$key]['wSum'] === 0) continue;
            $a = $acc[$key];
            $clusters[] = [
                'level' => 'island',
                'key'   => $key,
                'id'    => null,
                'name'  => IslandMap::label($key),
                'lat'   => $a['latSum'] / $a['wSum'],
                'lng'   => $a['lngSum'] / $a['wSum'],
                'count' => $a['count'],
                'drillable' => true,
            ];
        }

        return $this->wrap($clusters, 'island');
    }

    private function entityClusters(string $level): array
    {
        [$idExpr, $nameExpr] = match ($level) {
            'province' => [$this->provinceIdExpr(), $this->provinceNameExpr()],
            'city'     => [$this->cityIdExpr(), $this->cityNameExpr()],
            'barangay' => ['barangays.id', 'barangays.name'],
        };

        $rows = $this->baseInquiryQuery()
            ->groupBy(DB::raw($idExpr), DB::raw($nameExpr))
            ->get([
                DB::raw("{$idExpr} as gid"),
                DB::raw("{$nameExpr} as gname"),
                DB::raw('COUNT(*) as c'),
                DB::raw("{$this->latExpr()} as lat"),
                DB::raw("{$this->lngExpr()} as lng"),
            ]);

        $clusters = [];
        foreach ($rows as $r) {
            $lat = $r->lat !== null ? (float) $r->lat : null;
            $lng = $r->lng !== null ? (float) $r->lng : null;
            if (!$this->validPoint($lat, $lng)) continue; // unmappable group → no bubble

            $clusters[] = [
                'level'     => $level,
                'key'       => null,
                'id'        => $r->gid !== null ? (int) $r->gid : null,
                'name'      => (string) ($r->gname ?? 'Unknown'),
                'lat'       => $lat,
                'lng'       => $lng,
                'count'     => (int) $r->c,
                'drillable' => $level !== 'barangay' && $r->gid !== null,
            ];
        }

        return $this->wrap($clusters, $level);
    }

    private function wrap(array $clusters, string $level): array
    {
        usort($clusters, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'data'   => $clusters,
            'totals' => [
                'clusters'  => count($clusters),
                'max_count' => $clusters ? max(array_column($clusters, 'count')) : 0,
            ],
            'meta' => [
                'level'         => $level,
                'date_from'     => $this->dateFrom,
                'date_to'       => $this->dateTo,
                'category_id'   => $this->categoryId,
                'property_type' => $this->propertyType,
            ],
        ];
    }
}
