<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\DB;

/**
 * Province/city breakdown powering the "Projects by Province" dashboard
 * section — unique-project counts per province and per city, with optional
 * location/date scoping.
 */
class ProjectByProvinceService extends ProjectInsightsService
{
    /**
     * Province-level breakdown powering the "Projects by Province" section.
     * Returns the response payload (data + meta) ready to JSON-encode.
     */
    public function provinceBreakdown(string $sortBy = 'city_count', ?int $provinceId = null, ?int $cityId = null, ?string $dateStart = null, ?string $dateEnd = null): array
    {
        $projectKey = $this->projectKeyExpr();

        $ds = $dateStart ? $dateStart . ' 00:00:00' : null;
        $de = $dateEnd ? $dateEnd . ' 23:59:59' : null;

        // Optional province / city scope, applied to every aggregation query.
        $provIdExpr = 'COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)';
        $cityIdExpr = 'COALESCE(projects.city_id, property_cities.id)';
        $applyLoc = function ($q) use ($provinceId, $cityId, $provIdExpr, $cityIdExpr) {
            if ($provinceId !== null) {
                $q->whereRaw("{$provIdExpr} = ?", [$provinceId]);
            }
            if ($cityId !== null) {
                $q->whereRaw("{$cityIdExpr} = ?", [$cityId]);
            }
            return $q;
        };

        $projectListingCategories = DB::table('listings')
            ->select('listings.property_id', 'listings.category_id')
            ->whereNull('listings.deleted_at')
            ->when($ds, fn ($q) => $q->where('listings.created_at', '>=', $ds))
            ->when($de, fn ($q) => $q->where('listings.created_at', '<=', $de))
            ->distinct();

        $projectListingProperties = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->select('listings.property_id')
            ->whereNull('listings.deleted_at')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->when($ds, fn ($q) => $q->where('listings.created_at', '>=', $ds))
            ->when($de, fn ($q) => $q->where('listings.created_at', '<=', $de))
            ->distinct();

        // Cities — counts unique projects per (province, city). INNER joins
        // the listing-properties subquery so orphan is_project properties
        // with no active listing are intentionally excluded.
        $cityRows = $applyLoc($this->baseProjectDashboardQuery())
            ->joinSub($projectListingProperties, 'project_listing_properties', function ($join) {
                $join->on('project_listing_properties.property_id', '=', 'properties.id');
            })
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->whereNotNull(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name'),
                DB::raw('COALESCE(projects.city_id, property_cities.id) as city_id'),
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw("COUNT(DISTINCT {$projectKey}) as project_count")
            )
            ->groupByRaw('
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(project_provinces.name, property_provinces.name),
                COALESCE(projects.city_id, property_cities.id),
                COALESCE(project_cities.name, property_cities.name)
            ')
            ->orderBy('province_name')
            ->orderByDesc('project_count')
            ->orderBy('city_name')
            ->get();

        // === Per-city pivots ===
        // Project-dedup counts grouped per (province, city). Province-level
        // totals are derived from these in PHP (each project belongs to exactly
        // one city, so summing a province's cities reproduces the province
        // total) — that's two fewer aggregate queries than querying both levels.
        $cityCategoryRows = $applyLoc($this->baseProjectDashboardQuery())
            ->joinSub($projectListingCategories, 'project_listing_categories', function ($join) {
                $join->on('project_listing_categories.property_id', '=', 'properties.id');
            })
            ->join('categories', 'categories.id', '=', 'project_listing_categories.category_id')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->whereNotNull(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(projects.city_id, property_cities.id) as city_id'),
                'categories.name as category_name',
                DB::raw("COUNT(DISTINCT {$projectKey}) as project_count")
            )
            ->groupByRaw('
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(projects.city_id, property_cities.id),
                categories.name
            ')
            ->get();

        $cityTransactionRows = $applyLoc($this->baseProjectDashboardQuery())
            ->joinSub($projectListingProperties, 'project_listing_properties', function ($join) {
                $join->on('project_listing_properties.property_id', '=', 'properties.id');
            })
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->whereNotNull(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(projects.city_id, property_cities.id) as city_id'),
                'properties.status as status',
                DB::raw("COUNT(DISTINCT {$projectKey}) as transaction_count")
            )
            ->groupByRaw('
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(projects.city_id, property_cities.id),
                properties.status
            ')
            ->get();

        // Per-city pivots — keyed by "provinceId:cityId" for fast merge below.
        // Province maps are accumulated in the same pass.
        $categoryByCity = [];
        $categoryCountsByProvince = [];
        foreach ($cityCategoryRows as $row) {
            $provinceId = (int) $row->province_id;
            $key = $provinceId . ':' . (int) $row->city_id;
            $catKey = $this->categoryKey((string) $row->category_name);
            if ($catKey === null) {
                continue;
            }
            $categoryByCity[$key] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $categoryCountsByProvince[$provinceId] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $count = (int) $row->project_count;
            $categoryByCity[$key][$catKey] = $count;
            $categoryCountsByProvince[$provinceId][$catKey] += $count;
        }

        $transactionByCity = [];
        $transactionCountsByProvince = [];
        foreach ($cityTransactionRows as $row) {
            $provinceId = (int) $row->province_id;
            $key = $provinceId . ':' . (int) $row->city_id;
            $status = (string) $row->status;
            if (!in_array($status, self::TRANSACTION_STATUSES, true)) {
                continue;
            }
            $transactionByCity[$key] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            $transactionCountsByProvince[$provinceId] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            $count = (int) $row->transaction_count;
            $transactionByCity[$key][$status] = $count;
            $transactionCountsByProvince[$provinceId][$status] += $count;
        }

        // Build the province array.
        $provinces = [];
        foreach ($cityRows as $row) {
            $provinceId = (int) $row->province_id;
            $projectCount = (int) $row->project_count;

            if (!isset($provinces[$provinceId])) {
                $provinces[$provinceId] = [
                    'province_id' => $provinceId,
                    'province_name' => (string) $row->province_name,
                    'project_count' => 0,
                    'city_count' => 0,
                    'listing_breakdown' => $categoryCountsByProvince[$provinceId] ?? ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                    'transaction_breakdown' => $transactionCountsByProvince[$provinceId] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
                    'cities' => [],
                ];
            }

            $cityKey = $provinceId . ':' . (int) $row->city_id;
            $provinces[$provinceId]['project_count'] += $projectCount;
            $provinces[$provinceId]['city_count'] += 1;
            $provinces[$provinceId]['cities'][] = [
                'city_id' => (int) $row->city_id,
                'city_name' => (string) $row->city_name,
                'project_count' => $projectCount,
                'listing_breakdown' => $categoryByCity[$cityKey] ?? ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                'transaction_breakdown' => $transactionByCity[$cityKey] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
            ];
        }

        $provinceData = array_values($provinces);

        foreach ($provinceData as &$province) {
            usort($province['cities'], function (array $a, array $b) {
                return [$b['project_count'], $a['city_name']] <=> [$a['project_count'], $b['city_name']];
            });
        }
        unset($province);

        usort($provinceData, function (array $a, array $b) use ($sortBy) {
            return match ($sortBy) {
                'project_count' => [$b['project_count'], $b['city_count'], $a['province_name']] <=> [$a['project_count'], $a['city_count'], $b['province_name']],
                'for_sale'      => [$b['listing_breakdown']['for_sale'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_sale'], $a['city_count'], $b['province_name']],
                'for_rent'      => [$b['listing_breakdown']['for_rent'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_rent'], $a['city_count'], $b['province_name']],
                'foreclosure'   => [$b['listing_breakdown']['foreclosure'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['foreclosure'], $a['city_count'], $b['province_name']],
                'sold'          => [$b['transaction_breakdown']['sold'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['sold'], $a['city_count'], $b['province_name']],
                'rented'        => [$b['transaction_breakdown']['rented'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['rented'], $a['city_count'], $b['province_name']],
                'leased'        => [$b['transaction_breakdown']['leased'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['leased'], $a['city_count'], $b['province_name']],
                'province_name' => [$a['province_name'], $b['project_count']] <=> [$b['province_name'], $a['project_count']],
                default         => [$b['city_count'], $b['project_count'], $a['province_name']] <=> [$a['city_count'], $a['project_count'], $b['province_name']],
            };
        });

        return [
            'data' => $provinceData,
            'meta' => [
                'total_provinces'              => count($provinceData),
                // Each city belongs to exactly one province, so summing the
                // per-province city_count gives the unique city total.
                'total_cities'                 => array_sum(array_column($provinceData, 'city_count')),
                'total_projects'               => array_sum(array_column($provinceData, 'project_count')),
                'sort_by'                      => $sortBy,
            ],
        ];
    }

    /**
     * Map a category name to the dashboard's slug-style key. Returns null when
     * the category isn't one of the standard three.
     */
    private function categoryKey(string $name): ?string
    {
        return match ($name) {
            'For Sale'    => 'for_sale',
            'For Rent'    => 'for_rent',
            'Foreclosure' => 'foreclosure',
            default       => null,
        };
    }
}
