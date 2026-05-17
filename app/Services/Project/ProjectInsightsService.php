<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\DB;

/**
 * All heavy SQL + aggregation for the admin Project Insights dashboard
 * (province breakdown, project-by-name listing, single-project drill-down).
 * Extracted from ProjectController so the controller stays a thin
 * orchestration layer — auth, request parsing, JSON wrapping.
 *
 * Soft-delete invariants enforced everywhere:
 *   listings.deleted_at IS NULL
 *   properties.deleted_at IS NULL
 *   projects.deleted_at IS NULL (via leftJoin condition)
 *
 * "Standard categories" = For Sale / For Rent / Foreclosure. Other categories
 * (e.g., legacy / archived) are excluded from all counts and lists.
 */
class ProjectInsightsService
{
    private const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];
    private const TRANSACTION_STATUSES = ['sold', 'rented', 'leased'];

    /**
     * Group-key expression used by every aggregation:
     *   project:<id>    when the property is linked to a live project
     *   property:<id>   when the project is missing or soft-deleted (orphan)
     *
     * Uses projects.id (NULL on soft-deleted) instead of properties.project_id
     * (which still references the dead FK) — this matches what the drill-down
     * endpoint accepts so click-through never 404s.
     */
    private function projectKeyExpr(): string
    {
        return "CASE
            WHEN projects.id IS NULL THEN CONCAT('property:', properties.id)
            ELSE CONCAT('project:', projects.id)
        END";
    }

    /**
     * Base query: every is_project property that's still alive, joined to the
     * project (if any) and the full location resolution chain. Most insight
     * queries layer on top of this closure factory.
     */
    private function baseProjectDashboardQuery()
    {
        return DB::table('properties')
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('cities as project_cities', 'project_cities.id', '=', 'projects.city_id')
            ->leftJoin('provinces as project_provinces', 'project_provinces.id', '=', 'projects.prov_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces as property_provinces', 'property_provinces.id', '=', 'property_cities.province_id')
            ->whereNull('properties.deleted_at')
            ->where('properties.is_project', '=', 1);
    }

    /**
     * JSON-cast normalization: projects.featured_photo is cast to array on the
     * Eloquent model, but DB::table() bypasses casts. The raw column can be a
     * JSON array string, a plain URL, or null — return the first URL (or null).
     */
    private function normalizeFeaturedPhoto($value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '"')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return !empty($decoded[0]) ? (string) $decoded[0] : null;
                }
                if (is_string($decoded)) {
                    return $decoded;
                }
            }
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_array($value)) {
            return !empty($value[0]) ? (string) $value[0] : null;
        }
        return null;
    }

    /**
     * Province-level breakdown powering the "Projects by Province" section.
     * Returns the response payload (data + meta) ready to JSON-encode.
     */
    public function provinceBreakdown(string $sortBy = 'city_count'): array
    {
        $projectKey = $this->projectKeyExpr();

        $projectListingCategories = DB::table('listings')
            ->select('listings.property_id', 'listings.category_id')
            ->whereNull('listings.deleted_at')
            ->distinct();

        $projectListingProperties = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->select('listings.property_id')
            ->whereNull('listings.deleted_at')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->distinct();

        // Cities — counts unique projects per (province, city). INNER joins
        // the listing-properties subquery so orphan is_project properties
        // with no active listing are intentionally excluded.
        $cityRows = $this->baseProjectDashboardQuery()
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

        // Categories — project-dedup count per (province, category).
        $categoryRows = $this->baseProjectDashboardQuery()
            ->joinSub($projectListingCategories, 'project_listing_categories', function ($join) {
                $join->on('project_listing_categories.property_id', '=', 'properties.id');
            })
            ->join('categories', 'categories.id', '=', 'project_listing_categories.category_id')
            ->where(function ($query) {
                $query->whereNotNull('projects.prov_id')
                    ->orWhereNotNull('project_cities.province_id')
                    ->orWhereNotNull('property_cities.province_id');
            })
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                'categories.name as category_name',
                DB::raw("COUNT(DISTINCT {$projectKey}) as project_count")
            )
            ->groupByRaw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id), categories.name')
            ->get();

        // Transactions — project-dedup count per (province, status).
        $transactionRows = $this->baseProjectDashboardQuery()
            ->joinSub($projectListingProperties, 'project_listing_properties', function ($join) {
                $join->on('project_listing_properties.property_id', '=', 'properties.id');
            })
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->where(function ($query) {
                $query->whereNotNull('projects.prov_id')
                    ->orWhereNotNull('project_cities.province_id')
                    ->orWhereNotNull('property_cities.province_id');
            })
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                'properties.status as status',
                DB::raw("COUNT(DISTINCT {$projectKey}) as transaction_count")
            )
            ->groupByRaw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id), properties.status')
            ->get();

        // Raw listing rows per (province, category).
        $categoryListingRawRows = $this->baseProjectDashboardQuery()
            ->join('listings', function ($join) {
                $join->on('listings.property_id', '=', 'properties.id')
                    ->whereNull('listings.deleted_at');
            })
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->where(function ($query) {
                $query->whereNotNull('projects.prov_id')
                    ->orWhereNotNull('project_cities.province_id')
                    ->orWhereNotNull('property_cities.province_id');
            })
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                'categories.name as category_name',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id), categories.name')
            ->get();

        // Raw listing rows per (province, transaction status).
        $transactionListingRawRows = $this->baseProjectDashboardQuery()
            ->join('listings', function ($join) {
                $join->on('listings.property_id', '=', 'properties.id')
                    ->whereNull('listings.deleted_at');
            })
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->where(function ($query) {
                $query->whereNotNull('projects.prov_id')
                    ->orWhereNotNull('project_cities.province_id')
                    ->orWhereNotNull('property_cities.province_id');
            })
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                'properties.status as status',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id), properties.status')
            ->get();

        // === Per-city pivots ===
        // Same shape as the province-level rows above but grouped one level
        // deeper. Powers the per-city breakdown chips in the UI.
        $cityCategoryRows = $this->baseProjectDashboardQuery()
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

        $cityTransactionRows = $this->baseProjectDashboardQuery()
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

        $cityCategoryRawRows = $this->baseProjectDashboardQuery()
            ->join('listings', function ($join) {
                $join->on('listings.property_id', '=', 'properties.id')
                    ->whereNull('listings.deleted_at');
            })
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->whereNotNull(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(projects.city_id, property_cities.id) as city_id'),
                'categories.name as category_name',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(projects.city_id, property_cities.id),
                categories.name
            ')
            ->get();

        $cityTransactionRawRows = $this->baseProjectDashboardQuery()
            ->join('listings', function ($join) {
                $join->on('listings.property_id', '=', 'properties.id')
                    ->whereNull('listings.deleted_at');
            })
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->whereNotNull(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(projects.city_id, property_cities.id) as city_id'),
                'properties.status as status',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(projects.city_id, property_cities.id),
                properties.status
            ')
            ->get();

        // Pivot per-row results into per-province maps.
        $transactionCountsByProvince = [];
        foreach ($transactionRows as $row) {
            $provinceId = (int) $row->province_id;
            $status = (string) $row->status;
            $transactionCountsByProvince[$provinceId] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            if (in_array($status, self::TRANSACTION_STATUSES, true)) {
                $transactionCountsByProvince[$provinceId][$status] = (int) $row->transaction_count;
            }
        }

        $categoryCountsByProvince = [];
        foreach ($categoryRows as $row) {
            $provinceId = (int) $row->province_id;
            $categoryCountsByProvince[$provinceId] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $key = $this->categoryKey((string) $row->category_name);
            if ($key !== null) {
                $categoryCountsByProvince[$provinceId][$key] = (int) $row->project_count;
            }
        }

        $categoryListingRawByProvince = [];
        foreach ($categoryListingRawRows as $row) {
            $provinceId = (int) $row->province_id;
            $categoryListingRawByProvince[$provinceId] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $key = $this->categoryKey((string) $row->category_name);
            if ($key !== null) {
                $categoryListingRawByProvince[$provinceId][$key] = (int) $row->listing_count;
            }
        }

        $transactionListingRawByProvince = [];
        foreach ($transactionListingRawRows as $row) {
            $provinceId = (int) $row->province_id;
            $status = (string) $row->status;
            $transactionListingRawByProvince[$provinceId] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            if (in_array($status, self::TRANSACTION_STATUSES, true)) {
                $transactionListingRawByProvince[$provinceId][$status] = (int) $row->listing_count;
            }
        }

        // Per-city pivots — keyed by "provinceId:cityId" for fast merge below.
        $categoryByCity = [];
        foreach ($cityCategoryRows as $row) {
            $key = (int) $row->province_id . ':' . (int) $row->city_id;
            $categoryByCity[$key] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $catKey = $this->categoryKey((string) $row->category_name);
            if ($catKey !== null) {
                $categoryByCity[$key][$catKey] = (int) $row->project_count;
            }
        }

        $transactionByCity = [];
        foreach ($cityTransactionRows as $row) {
            $key = (int) $row->province_id . ':' . (int) $row->city_id;
            $status = (string) $row->status;
            $transactionByCity[$key] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            if (in_array($status, self::TRANSACTION_STATUSES, true)) {
                $transactionByCity[$key][$status] = (int) $row->transaction_count;
            }
        }

        $categoryRawByCity = [];
        foreach ($cityCategoryRawRows as $row) {
            $key = (int) $row->province_id . ':' . (int) $row->city_id;
            $categoryRawByCity[$key] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $catKey = $this->categoryKey((string) $row->category_name);
            if ($catKey !== null) {
                $categoryRawByCity[$key][$catKey] = (int) $row->listing_count;
            }
        }

        $transactionRawByCity = [];
        foreach ($cityTransactionRawRows as $row) {
            $key = (int) $row->province_id . ':' . (int) $row->city_id;
            $status = (string) $row->status;
            $transactionRawByCity[$key] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            if (in_array($status, self::TRANSACTION_STATUSES, true)) {
                $transactionRawByCity[$key][$status] = (int) $row->listing_count;
            }
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
                    'listing_breakdown_raw' => $categoryListingRawByProvince[$provinceId] ?? ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                    'transaction_breakdown' => $transactionCountsByProvince[$provinceId] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
                    'transaction_breakdown_raw' => $transactionListingRawByProvince[$provinceId] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
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
                'listing_breakdown_raw' => $categoryRawByCity[$cityKey] ?? ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                'transaction_breakdown' => $transactionByCity[$cityKey] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
                'transaction_breakdown_raw' => $transactionRawByCity[$cityKey] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
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

        // Global totals (both project-dedup and raw listing-row).
        $totals = [
            'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0,
            'sold' => 0, 'rented' => 0, 'leased' => 0,
            'listings_for_sale' => 0, 'listings_for_rent' => 0, 'listings_foreclosure' => 0,
            'listings_sold' => 0, 'listings_rented' => 0, 'listings_leased' => 0,
        ];
        foreach ($provinceData as $province) {
            $totals['for_sale']             += $province['listing_breakdown']['for_sale']         ?? 0;
            $totals['for_rent']             += $province['listing_breakdown']['for_rent']         ?? 0;
            $totals['foreclosure']          += $province['listing_breakdown']['foreclosure']      ?? 0;
            $totals['sold']                 += $province['transaction_breakdown']['sold']         ?? 0;
            $totals['rented']               += $province['transaction_breakdown']['rented']       ?? 0;
            $totals['leased']               += $province['transaction_breakdown']['leased']       ?? 0;
            $totals['listings_for_sale']    += $province['listing_breakdown_raw']['for_sale']     ?? 0;
            $totals['listings_for_rent']    += $province['listing_breakdown_raw']['for_rent']     ?? 0;
            $totals['listings_foreclosure'] += $province['listing_breakdown_raw']['foreclosure']  ?? 0;
            $totals['listings_sold']        += $province['transaction_breakdown_raw']['sold']     ?? 0;
            $totals['listings_rented']      += $province['transaction_breakdown_raw']['rented']   ?? 0;
            $totals['listings_leased']      += $province['transaction_breakdown_raw']['leased']   ?? 0;
        }
        $totalListings = $totals['listings_for_sale'] + $totals['listings_for_rent'] + $totals['listings_foreclosure'];

        return [
            'data' => $provinceData,
            'meta' => [
                'total_provinces'              => count($provinceData),
                // Each city belongs to exactly one province, so summing the
                // per-province city_count gives the unique city total.
                'total_cities'                 => array_sum(array_column($provinceData, 'city_count')),
                'total_projects'               => array_sum(array_column($provinceData, 'project_count')),
                'total_listings'               => $totalListings,
                'total_for_sale'               => $totals['for_sale'],
                'total_for_rent'               => $totals['for_rent'],
                'total_foreclosure'            => $totals['foreclosure'],
                'total_sold'                   => $totals['sold'],
                'total_rented'                 => $totals['rented'],
                'total_leased'                 => $totals['leased'],
                'total_listings_for_sale'      => $totals['listings_for_sale'],
                'total_listings_for_rent'      => $totals['listings_for_rent'],
                'total_listings_foreclosure'   => $totals['listings_foreclosure'],
                'total_listings_sold'          => $totals['listings_sold'],
                'total_listings_rented'        => $totals['listings_rented'],
                'total_listings_leased'        => $totals['listings_leased'],
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

    private function categoryFilterValue(string $raw): ?string
    {
        return match (strtolower($raw)) {
            'for-sale'    => 'For Sale',
            'for-rent'    => 'For Rent',
            'foreclosure' => 'Foreclosure',
            default       => null,
        };
    }

    /**
     * Paginated list of every project (and standalone is_project=1 property
     * with no project_id) with its full stat breakdown + dashboard
     * aggregates (top project, leaderboards, category mix).
     *
     * @param  array{
     *     page?:int, per_page?:int, search?:string,
     *     sort_by?:string, category?:string
     * } $params
     */
    public function projectsByName(array $params): array
    {
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $search   = trim((string) ($params['search'] ?? ''));
        $sortBy   = (string) ($params['sort_by'] ?? 'total_listings');
        $category = (string) ($params['category'] ?? '');

        $categoryFilter = $this->categoryFilterValue($category);
        $projectKey     = $this->projectKeyExpr();

        // 1) Base — only projects with at least one matching active listing.
        $base = $this->baseProjectDashboardQuery()
            ->joinSub(
                DB::table('listings')
                    ->join('categories', 'categories.id', '=', 'listings.category_id')
                    ->select('listings.property_id')
                    ->whereNull('listings.deleted_at')
                    ->whereIn('categories.name', self::STANDARD_CATEGORIES)
                    ->when($categoryFilter, fn ($q) => $q->where('categories.name', $categoryFilter))
                    ->distinct(),
                'project_listing_properties',
                fn ($join) => $join->on('project_listing_properties.property_id', '=', 'properties.id')
            );

        if ($search !== '') {
            $like = '%' . $search . '%';
            $base->where(function ($q) use ($like) {
                $q->where('projects.name', 'like', $like)
                  ->orWhere('properties.name', 'like', $like);
            });
        }

        // 2) Aggregate per project_key. Left-join a SUBQUERY of listings
        //    pre-filtered to standard categories + optional category — keeps
        //    counts in lockstep with the gating subquery.
        $listingsFiltered = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereNull('listings.deleted_at')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->when($categoryFilter, fn ($q) => $q->where('categories.name', $categoryFilter))
            ->select(
                'listings.id',
                'listings.property_id',
                'listings.updated_at',
                DB::raw('categories.name as category_name')
            );

        $aggregated = (clone $base)
            ->leftJoinSub($listingsFiltered, 'listings_filtered', function ($join) {
                $join->on('listings_filtered.property_id', '=', 'properties.id');
            })
            ->select(
                DB::raw("{$projectKey} as project_key"),
                'properties.project_id',
                DB::raw('COALESCE(projects.name, properties.name) as project_name'),
                'projects.slug as project_slug',
                'projects.featured_photo',
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name'),
                DB::raw('COUNT(listings_filtered.id) as total_listings'),
                DB::raw("SUM(CASE WHEN listings_filtered.category_name = 'For Sale' THEN 1 ELSE 0 END) as for_sale"),
                DB::raw("SUM(CASE WHEN listings_filtered.category_name = 'For Rent' THEN 1 ELSE 0 END) as for_rent"),
                DB::raw("SUM(CASE WHEN listings_filtered.category_name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure"),
                DB::raw("SUM(CASE WHEN properties.status = 'sold' AND listings_filtered.id IS NOT NULL THEN 1 ELSE 0 END) as sold"),
                DB::raw("SUM(CASE WHEN properties.status = 'rented' AND listings_filtered.id IS NOT NULL THEN 1 ELSE 0 END) as rented"),
                DB::raw("SUM(CASE WHEN properties.status = 'leased' AND listings_filtered.id IS NOT NULL THEN 1 ELSE 0 END) as leased")
            )
            ->groupByRaw("
                {$projectKey},
                properties.project_id,
                COALESCE(projects.name, properties.name),
                projects.slug,
                projects.featured_photo,
                COALESCE(project_cities.name, property_cities.name),
                COALESCE(project_provinces.name, property_provinces.name)
            ");

        match ($sortBy) {
            'name'  => $aggregated->orderByRaw('COALESCE(projects.name, properties.name) ASC'),
            default => $aggregated->orderByDesc('total_listings'),
        };

        // 3) Manual pagination — group-by queries don't paginate cleanly via
        //    the LengthAwarePaginator across all MySQL versions.
        $allRows = $aggregated->get();
        $total   = $allRows->count();
        $sliced  = $allRows->slice(($page - 1) * $perPage, $perPage)->values();

        $rows = $sliced->map(fn ($row) => [
            'project_key'           => (string) $row->project_key,
            'project_id'            => $row->project_id !== null ? (int) $row->project_id : null,
            'project_name'          => (string) $row->project_name,
            'project_slug'          => $row->project_slug,
            'featured_photo'        => $this->normalizeFeaturedPhoto($row->featured_photo),
            'city_name'             => $row->city_name,
            'province_name'         => $row->province_name,
            'total_listings'        => (int) $row->total_listings,
            'listing_breakdown'     => [
                'for_sale'    => (int) $row->for_sale,
                'for_rent'    => (int) $row->for_rent,
                'foreclosure' => (int) $row->foreclosure,
            ],
            'transaction_breakdown' => [
                'sold'   => (int) $row->sold,
                'rented' => (int) $row->rented,
                'leased' => (int) $row->leased,
            ],
        ]);

        $lastPage = max(1, (int) ceil($total / $perPage));

        // 4) Aggregates across ALL filtered rows — powers the dashboard's
        //    insights strip + Top 50 leaderboards. Stays accurate as the
        //    user paginates.
        $aggregates = $this->buildByNameAggregates($allRows, $total);

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
                'sort_by'      => $sortBy,
                'search'       => $search,
                'category'     => $categoryFilter,
            ],
            'aggregates' => $aggregates,
        ];
    }

    /**
     * Compute the by-name aggregates block (insights strip + Top 50 lists).
     */
    private function buildByNameAggregates($allRows, int $total): array
    {
        $sum = [
            'total_listings' => 0,
            'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0,
            'sold' => 0, 'rented' => 0, 'leased' => 0,
        ];
        foreach ($allRows as $r) {
            $sum['total_listings'] += (int) $r->total_listings;
            $sum['for_sale']       += (int) $r->for_sale;
            $sum['for_rent']       += (int) $r->for_rent;
            $sum['foreclosure']    += (int) $r->foreclosure;
            $sum['sold']           += (int) $r->sold;
            $sum['rented']         += (int) $r->rented;
            $sum['leased']         += (int) $r->leased;
        }

        $byListings = $allRows->sortByDesc(fn ($r) => (int) $r->total_listings)->values();
        $byTransactions = $allRows
            ->sortByDesc(fn ($r) => ((int) $r->sold) + ((int) $r->rented) + ((int) $r->leased))
            ->values();

        $topProject = $byListings->first();

        $topByListings = $byListings->take(50)->map(fn ($r) => [
            'project_key'    => (string) $r->project_key,
            'project_name'   => (string) $r->project_name,
            'total_listings' => (int) $r->total_listings,
        ])->values();

        $topByTransactions = $byTransactions
            ->filter(fn ($r) => ((int) $r->sold) + ((int) $r->rented) + ((int) $r->leased) > 0)
            ->take(50)
            ->map(function ($r) {
                $sold   = (int) $r->sold;
                $rented = (int) $r->rented;
                $leased = (int) $r->leased;
                return [
                    'project_key'        => (string) $r->project_key,
                    'project_name'       => (string) $r->project_name,
                    'sold'               => $sold,
                    'rented'             => $rented,
                    'leased'             => $leased,
                    'total_transactions' => $sold + $rented + $leased,
                ];
            })->values();

        return [
            'total_projects'      => $total,
            'total_listings'      => $sum['total_listings'],
            'total_for_sale'      => $sum['for_sale'],
            'total_for_rent'      => $sum['for_rent'],
            'total_foreclosure'   => $sum['foreclosure'],
            'total_sold'          => $sum['sold'],
            'total_rented'        => $sum['rented'],
            'total_leased'        => $sum['leased'],
            'top_project'         => $topProject ? [
                'project_key'    => (string) $topProject->project_key,
                'project_name'   => (string) $topProject->project_name,
                'total_listings' => (int) $topProject->total_listings,
            ] : null,
            'top_by_listings'     => $topByListings,
            'top_by_transactions' => $topByTransactions,
        ];
    }

    /**
     * Single-project drill-down — resolves the project entity, computes the
     * full totals, and returns a paginated listings list filtered by an
     * optional status / category. Returns null when the key doesn't resolve.
     *
     * @param  array{
     *     page?:int, per_page?:int,
     *     status?:string, category?:string
     * } $params
     */
    public function projectDetail(string $projectKey, array $params): ?array
    {
        if (!preg_match('/^(project|property):(\d+)$/', $projectKey, $matches)) {
            return null;
        }
        $kind = $matches[1];
        $id   = (int) $matches[2];

        $page           = max(1, (int) ($params['page'] ?? 1));
        $perPage        = max(1, min(100, (int) ($params['per_page'] ?? 50)));
        $statusFilter   = (string) ($params['status'] ?? '');
        $categoryFilter = (string) ($params['category'] ?? '');

        // 1) Resolve the project entity + property set this drawer covers.
        $resolved = $this->resolveProjectEntity($kind, $id);
        if ($resolved === null) {
            return null;
        }
        [$project, $propertyIds] = $resolved;

        // 2) Totals query — listings on the resolved property set, filtered
        //    to standard categories, unfiltered by user status/category so
        //    drawer mini-stats keep showing the full picture.
        $totalsQuery = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereIn('listings.property_id', $propertyIds)
            ->whereIn('categories.name', self::STANDARD_CATEGORIES);

        $totalsRow = (clone $totalsQuery)
            ->select(
                DB::raw('COUNT(listings.id) as total_listings'),
                DB::raw("SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale"),
                DB::raw("SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent"),
                DB::raw("SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure"),
                DB::raw("SUM(CASE WHEN properties.status = 'sold' THEN 1 ELSE 0 END) as sold"),
                DB::raw("SUM(CASE WHEN properties.status = 'rented' THEN 1 ELSE 0 END) as rented"),
                DB::raw("SUM(CASE WHEN properties.status = 'leased' THEN 1 ELSE 0 END) as leased")
            )
            ->first();

        // 3) Listings query — clones totals, layers user filters, paginates.
        $listingsQuery = (clone $totalsQuery)
            ->when($statusFilter !== '', function ($q) use ($statusFilter) {
                if ($statusFilter === 'active') {
                    // Closure-wrap so the OR doesn't escape into the outer
                    // WHEREs (deleted filters, propertyIds, etc.).
                    $q->where(function ($qq) {
                        $qq->where('properties.status', 'active')
                            ->orWhereNull('properties.status');
                    });
                } else {
                    $q->where('properties.status', $statusFilter);
                }
            })
            ->when($categoryFilter !== '', function ($q) use ($categoryFilter) {
                $name = $this->categoryFilterValue($categoryFilter);
                if ($name) {
                    $q->where('categories.name', $name);
                }
            })
            ->select(
                'listings.id',
                'listings.code',
                'listings.name as listing_name',
                'listings.slug',
                'listings.price',
                'listings.visibility',
                'listings.is_featured',
                'listings.featured_photo',
                'listings.created_at',
                'listings.updated_at',
                'properties.status as property_status',
                DB::raw('categories.name as category_name')
            )
            ->orderByDesc('listings.updated_at');

        $totalListingsCount = (clone $listingsQuery)->count();
        $rows = $listingsQuery
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $listings = $rows->map(fn ($row) => [
            'id'              => (int) $row->id,
            'code'            => (string) $row->code,
            'name'            => (string) $row->listing_name,
            'slug'            => $row->slug,
            'price'           => $row->price,
            'category_name'   => $row->category_name,
            'property_status' => $row->property_status,
            'visibility'      => $row->visibility,
            'is_featured'     => (bool) $row->is_featured,
            'image'           => $this->normalizeFeaturedPhoto($row->featured_photo),
            'created_at'      => $row->created_at,
            'updated_at'      => $row->updated_at,
        ]);

        return [
            'project' => [
                'id'               => $project->id,
                'name'             => $project->name,
                'slug'             => $project->slug,
                'featured_photo'   => $this->normalizeFeaturedPhoto($project->featured_photo ?? null),
                'city'             => $project->city_name,
                'province'         => $project->province_name,
                'complete_address' => $project->complete_address ?? null,
                'views'            => $project->views,
                'project_key'      => $projectKey,
            ],
            'totals' => [
                'total_listings' => (int) ($totalsRow->total_listings ?? 0),
                'for_sale'       => (int) ($totalsRow->for_sale       ?? 0),
                'for_rent'       => (int) ($totalsRow->for_rent       ?? 0),
                'foreclosure'    => (int) ($totalsRow->foreclosure    ?? 0),
                'sold'           => (int) ($totalsRow->sold           ?? 0),
                'rented'         => (int) ($totalsRow->rented         ?? 0),
                'leased'         => (int) ($totalsRow->leased         ?? 0),
            ],
            'listings' => $listings,
            'meta' => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($totalListingsCount / $perPage)),
                'per_page'     => $perPage,
                'total'        => $totalListingsCount,
            ],
        ];
    }

    /**
     * Resolve "project:<id>" or "property:<id>" into [entity, propertyIds].
     * Returns null when the entity doesn't exist (or is soft-deleted).
     *
     * @return array{0:object,1:\Illuminate\Support\Collection<int>}|null
     */
    private function resolveProjectEntity(string $kind, int $id): ?array
    {
        if ($kind === 'project') {
            $project = DB::table('projects')
                ->leftJoin('cities', 'cities.id', '=', 'projects.city_id')
                ->leftJoin('provinces', 'provinces.id', '=', 'projects.prov_id')
                ->whereNull('projects.deleted_at')
                ->where('projects.id', $id)
                ->select(
                    'projects.id',
                    'projects.name',
                    'projects.slug',
                    'projects.featured_photo',
                    DB::raw('cities.name as city_name'),
                    DB::raw('provinces.name as province_name'),
                    'projects.complete_address',
                    'projects.views'
                )
                ->first();
            if (!$project) {
                return null;
            }
            $propertyIds = DB::table('properties')
                ->where('project_id', $id)
                ->where('is_project', 1)
                ->whereNull('deleted_at')
                ->pluck('id');

            return [$project, $propertyIds];
        }

        // Standalone is_project=1 property — no project_id.
        $property = DB::table('properties')
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities', 'cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces', 'provinces.id', '=', 'cities.province_id')
            ->where('properties.id', $id)
            ->where('properties.is_project', 1)
            ->whereNull('properties.deleted_at')
            ->select(
                'properties.id',
                'properties.name',
                'properties.address',
                'properties.featured_photo',
                DB::raw('cities.name as city_name'),
                DB::raw('provinces.name as province_name')
            )
            ->first();
        if (!$property) {
            return null;
        }

        $synthetic = (object) [
            'id'               => null,
            'name'             => $property->name,
            'slug'             => null,
            'featured_photo'   => $property->featured_photo,
            'city_name'        => $property->city_name,
            'province_name'    => $property->province_name,
            'complete_address' => $property->address,
            'views'            => null,
        ];

        return [$synthetic, collect([$id])];
    }
}
