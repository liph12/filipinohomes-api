<?php

namespace App\Services\Listing;

use App\Support\IslandMap;
use Illuminate\Support\Facades\DB;

/**
 * Compact overview numbers for the Listing Insights section tiles (bento
 * stats): totals, category / transaction splits, per-type counts, per-island
 * counts, and this-month created counts — all in one call.
 */
class ListingSummaryService extends ListingInsightsService
{
    public function summary(array $params = [], ?array $agentIds = null): array
    {
        $this->agentIds = $agentIds;
        $this->dateStart = $params['date_start'] ?? null;
        $this->dateEnd = $params['date_end'] ?? null;
        $this->provinceId = isset($params['province_id']) && $params['province_id'] !== null ? (int) $params['province_id'] : null;
        $this->cityId = isset($params['city_id']) && $params['city_id'] !== null ? (int) $params['city_id'] : null;
        $this->island = is_string($params['island'] ?? null) ? $params['island'] : null;
        $this->region = is_string($params['region'] ?? null) ? $params['region'] : null;
        $this->barangayId = isset($params['barangay_id']) && $params['barangay_id'] !== null ? (int) $params['barangay_id'] : null;
        $this->resolveScopeProvinceIds();

        $monthStart = date('Y-m-01').' 00:00:00';

        // ── Main aggregate: totals, category + transaction splits, distinct
        //    province/city counts, and this-month created counts. ──
        $agg = $this->baseListingQuery()
            ->selectRaw("
                COUNT(listings.id) as total,
                SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale,
                SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent,
                SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure,
                SUM(CASE WHEN properties.status = 'sold' THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN properties.status = 'rented' THEN 1 ELSE 0 END) as rented,
                SUM(CASE WHEN properties.status = 'leased' THEN 1 ELSE 0 END) as leased,
                COUNT(DISTINCT COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)) as provinces,
                COUNT(DISTINCT COALESCE(projects.city_id, property_cities.id)) as cities,
                SUM(CASE WHEN listings.created_at >= ? THEN 1 ELSE 0 END) as month_total,
                SUM(CASE WHEN categories.name = 'For Sale' AND listings.created_at >= ? THEN 1 ELSE 0 END) as month_for_sale,
                SUM(CASE WHEN categories.name = 'For Rent' AND listings.created_at >= ? THEN 1 ELSE 0 END) as month_for_rent,
                SUM(CASE WHEN categories.name = 'Foreclosure' AND listings.created_at >= ? THEN 1 ELSE 0 END) as month_foreclosure
            ", [$monthStart, $monthStart, $monthStart, $monthStart])
            ->first();

        // ── Per property type (House / Condominium / Land / Commercial). ──
        $typeRows = $this->baseListingQuery()
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->select('property_types.name as type', DB::raw('COUNT(listings.id) as c'))
            ->groupBy('property_types.name')
            ->get();
        $types = ['house' => 0, 'condominium' => 0, 'land' => 0, 'commercial' => 0];
        foreach ($typeRows as $r) {
            $k = strtolower(trim((string) $r->type));
            if (array_key_exists($k, $types)) {
                $types[$k] = (int) $r->c;
            }
        }

        // ── Per island (fold provinces into Luzon / Visayas / Mindanao). ──
        $provRows = $this->baseListingQuery()
            ->select(
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name'),
                DB::raw('COUNT(listings.id) as c')
            )
            ->groupByRaw('COALESCE(project_provinces.name, property_provinces.name)')
            ->get();
        $islands = ['luzon' => 0, 'visayas' => 0, 'mindanao' => 0];
        foreach ($provRows as $r) {
            if (! $r->province_name) {
                continue;
            }
            $isl = IslandMap::islandOf($r->province_name);
            if ($isl && array_key_exists($isl, $islands)) {
                $islands[$isl] += (int) $r->c;
            }
        }

        return [
            'total' => (int) ($agg->total ?? 0),
            'provinces' => (int) ($agg->provinces ?? 0),
            'cities' => (int) ($agg->cities ?? 0),
            'for_sale' => (int) ($agg->for_sale ?? 0),
            'for_rent' => (int) ($agg->for_rent ?? 0),
            'foreclosure' => (int) ($agg->foreclosure ?? 0),
            'sold' => (int) ($agg->sold ?? 0),
            'rented' => (int) ($agg->rented ?? 0),
            'leased' => (int) ($agg->leased ?? 0),
            'types' => $types,
            'islands' => $islands,
            'month' => [
                'total' => (int) ($agg->month_total ?? 0),
                'for_sale' => (int) ($agg->month_for_sale ?? 0),
                'for_rent' => (int) ($agg->month_for_rent ?? 0),
                'foreclosure' => (int) ($agg->month_foreclosure ?? 0),
            ],
        ];
    }
}
