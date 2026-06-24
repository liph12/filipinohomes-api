<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * "Properties by Type" — one row per property type with subtype children and
 * category + transaction-status counts.
 */
class ListingByTypeService extends ListingInsightsService
{
    /**
     * Type-level breakdown. Returns one row per property type with subtype
     * children and category + transaction-status counts for each. Drives the
     * "Properties by Type" panel on the Listing Insights page.
     *
     * $dateStart / $dateEnd optionally constrain counts to listings created
     * within the inclusive window (YYYY-MM-DD).
     */
    public function typeBreakdown(?string $dateStart = null, ?string $dateEnd = null, ?array $agentIds = null, ?int $cityId = null, ?int $provinceId = null, ?string $island = null, ?string $region = null, ?int $barangayId = null): array
    {
        $this->agentIds = $agentIds;
        // Resolve a region/island scope into a province whitelist (no-op when a
        // province/city is already set). This method builds its own slim query,
        // so the scope is applied inline below rather than via baseListingQuery().
        $this->provinceId = $provinceId;
        $this->cityId = $cityId;
        $this->island = $island;
        $this->region = $region;
        $this->barangayId = $barangayId;
        $this->resolveScopeProvinceIds();

        // Slimmer query than baseListingQuery() — this breakdown doesn't need
        // any location joins (projects / cities / provinces / barangays),
        // which saves 6+ LEFT JOINs on the listings table.
        $query = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            // Exclude listings whose property was marked deleted (status, not soft-delete).
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES);

        // Location scope — only then pay for the joins needed to resolve a
        // listing's location (project's city/prov, else the property's barangay
        // → city → province). Fires for an explicit city/province, a region/island
        // (resolved to a province whitelist), or a barangay.
        if ($cityId !== null || $provinceId !== null || $this->scopeProvinceIds !== null || $barangayId !== null) {
            $query->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')->whereNull('projects.deleted_at');
            })
                ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
                ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id');

            if ($cityId !== null) {
                $query->where(DB::raw('COALESCE(projects.city_id, property_cities.id)'), $cityId);
            }
            if ($provinceId !== null) {
                $query->where(DB::raw('COALESCE(projects.prov_id, property_cities.province_id)'), $provinceId);
            }
            if ($this->scopeProvinceIds !== null) {
                $query->whereIn(DB::raw('COALESCE(projects.prov_id, property_cities.province_id)'), $this->scopeProvinceIds ?: [0]);
            }
            if ($barangayId !== null) {
                $query->where('properties.address_id', $barangayId);
            }
        }

        if ($this->agentIds !== null) {
            $query->whereIn('listings.agent_id', $this->agentIds);
        }
        if ($dateStart !== null && $dateStart !== '') {
            $query->where('listings.created_at', '>=', $dateStart.' 00:00:00');
        }
        if ($dateEnd !== null && $dateEnd !== '') {
            $query->where('listings.created_at', '<=', $dateEnd.' 23:59:59');
        }

        $rows = $query
            ->select(
                'property_types.id as type_id',
                'property_types.name as type_name',
                'property_subtypes.id as subtype_id',
                'property_subtypes.name as subtype_name',
                'categories.name as category_name',
                'properties.status as status',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('
                property_types.id, property_types.name,
                property_subtypes.id, property_subtypes.name,
                categories.name, properties.status
            ')
            ->get();

        $emptyStats = [
            'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0,
            'sold' => 0, 'rented' => 0, 'leased' => 0, 'total' => 0,
        ];

        $types = [];
        foreach ($rows as $row) {
            $typeId = (int) $row->type_id;
            $subtypeId = (int) $row->subtype_id;
            $count = (int) $row->listing_count;

            if (! isset($types[$typeId])) {
                $types[$typeId] = [
                    'type_id' => $typeId,
                    'type' => (string) $row->type_name,
                    'aggregate' => $emptyStats,
                    'subtypes' => [],
                ];
            }
            if (! isset($types[$typeId]['subtypes'][$subtypeId])) {
                $types[$typeId]['subtypes'][$subtypeId] = [
                    'subtype_id' => $subtypeId,
                    'subType' => (string) $row->subtype_name,
                    'statistics' => $emptyStats,
                ];
            }

            $catKey = $this->categoryKey((string) $row->category_name);
            if ($catKey !== null) {
                $types[$typeId]['subtypes'][$subtypeId]['statistics'][$catKey] += $count;
                $types[$typeId]['aggregate'][$catKey] += $count;
            }

            $status = strtolower((string) ($row->status ?? ''));
            if (in_array($status, self::TRANSACTION_STATUSES, true)) {
                $types[$typeId]['subtypes'][$subtypeId]['statistics'][$status] += $count;
                $types[$typeId]['aggregate'][$status] += $count;
            }

            $types[$typeId]['subtypes'][$subtypeId]['statistics']['total'] += $count;
            $types[$typeId]['aggregate']['total'] += $count;
        }

        $typeData = array_values(array_map(function (array $t) {
            $t['subtypes'] = array_values($t['subtypes']);
            usort($t['subtypes'], fn ($a, $b) => $b['statistics']['total'] <=> $a['statistics']['total']);

            return $t;
        }, $types));

        usort($typeData, fn ($a, $b) => $b['aggregate']['total'] <=> $a['aggregate']['total']);

        $totals = $emptyStats;
        foreach ($typeData as $t) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $t['aggregate'][$k] ?? 0;
            }
        }

        return [
            'data' => $typeData,
            'meta' => [
                'total_types' => count($typeData),
                'total_listings' => $totals['total'],
                'totals' => $totals,
            ],
        ];
    }
}
