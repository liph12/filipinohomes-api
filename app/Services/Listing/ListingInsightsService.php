<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * Aggregation queries for the admin "Listing Insights" dashboard.
 *
 * Counterpart to ProjectInsightsService — same shape, but operates on every
 * listing (no `properties.is_project = 1` filter), so project units and
 * standalone listings are treated equally. Counts are listing-row counts
 * (not project-dedup counts).
 *
 * Soft-delete invariants enforced everywhere:
 *   listings.deleted_at IS NULL
 *   properties.deleted_at IS NULL
 *
 * "Standard categories" = For Sale / For Rent / Foreclosure. Other categories
 * are excluded from every count.
 */
class ListingInsightsService
{
    private const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];
    private const TRANSACTION_STATUSES = ['sold', 'rented', 'leased'];

    /**
     * Base join chain — listings → properties → location resolution.
     * Every aggregation query layers on top of this closure.
     */
    private function baseListingQuery()
    {
        return DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('cities as project_cities', 'project_cities.id', '=', 'projects.city_id')
            ->leftJoin('provinces as project_provinces', 'project_provinces.id', '=', 'projects.prov_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces as property_provinces', 'property_provinces.id', '=', 'property_cities.province_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES);
    }

    private function categoryKey(string $name): ?string
    {
        return match ($name) {
            'For Sale'    => 'for_sale',
            'For Rent'    => 'for_rent',
            'Foreclosure' => 'foreclosure',
            default       => null,
        };
    }

    /**
     * Province-level breakdown. Returns one row per (province, city) with
     * listing-count metrics, then groups into provinces with cities[].
     */
    public function provinceBreakdown(string $sortBy = 'listing_count'): array
    {
        // Cities — count listings per (province, city).
        $cityRows = $this->baseListingQuery()
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->whereNotNull(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->select(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name'),
                DB::raw('COALESCE(projects.city_id, property_cities.id) as city_id'),
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(project_provinces.name, property_provinces.name),
                COALESCE(projects.city_id, property_cities.id),
                COALESCE(project_cities.name, property_cities.name)
            ')
            ->orderBy('province_name')
            ->orderByDesc('listing_count')
            ->orderBy('city_name')
            ->get();

        // Categories — listing count per (province, category).
        $categoryRows = $this->baseListingQuery()
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

        // Transactions — listing count per (province, properties.status), for sold/rented/leased only.
        $transactionRows = $this->baseListingQuery()
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

        // Categories per (province, city) — feeds the city-row breakdown chips.
        $cityCategoryRows = $this->baseListingQuery()
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

        // Transactions per (province, city) — for sold/rented/leased only.
        $cityTransactionRows = $this->baseListingQuery()
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

        // Pivot into per-province maps.
        $categoryByProvince = [];
        foreach ($categoryRows as $row) {
            $provinceId = (int) $row->province_id;
            $categoryByProvince[$provinceId] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $key = $this->categoryKey((string) $row->category_name);
            if ($key !== null) {
                $categoryByProvince[$provinceId][$key] = (int) $row->listing_count;
            }
        }

        $transactionByProvince = [];
        foreach ($transactionRows as $row) {
            $provinceId = (int) $row->province_id;
            $status = (string) $row->status;
            $transactionByProvince[$provinceId] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            if (in_array($status, self::TRANSACTION_STATUSES, true)) {
                $transactionByProvince[$provinceId][$status] = (int) $row->listing_count;
            }
        }

        // Per-city pivots — keyed by "provinceId:cityId" for fast merge below.
        $categoryByCity = [];
        foreach ($cityCategoryRows as $row) {
            $key = (int) $row->province_id . ':' . (int) $row->city_id;
            $categoryByCity[$key] ??= ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0];
            $catKey = $this->categoryKey((string) $row->category_name);
            if ($catKey !== null) {
                $categoryByCity[$key][$catKey] = (int) $row->listing_count;
            }
        }

        $transactionByCity = [];
        foreach ($cityTransactionRows as $row) {
            $key = (int) $row->province_id . ':' . (int) $row->city_id;
            $status = (string) $row->status;
            $transactionByCity[$key] ??= ['sold' => 0, 'rented' => 0, 'leased' => 0];
            if (in_array($status, self::TRANSACTION_STATUSES, true)) {
                $transactionByCity[$key][$status] = (int) $row->listing_count;
            }
        }

        // Assemble province array.
        $provinces = [];
        foreach ($cityRows as $row) {
            $provinceId = (int) $row->province_id;
            $count = (int) $row->listing_count;

            if (!isset($provinces[$provinceId])) {
                $provinces[$provinceId] = [
                    'province_id' => $provinceId,
                    'province_name' => (string) $row->province_name,
                    'listing_count' => 0,
                    'city_count' => 0,
                    'listing_breakdown' => $categoryByProvince[$provinceId] ?? ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                    'transaction_breakdown' => $transactionByProvince[$provinceId] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
                    'cities' => [],
                ];
            }

            $cityKey = $provinceId . ':' . (int) $row->city_id;
            $provinces[$provinceId]['listing_count'] += $count;
            $provinces[$provinceId]['city_count'] += 1;
            $provinces[$provinceId]['cities'][] = [
                'city_id' => (int) $row->city_id,
                'city_name' => (string) $row->city_name,
                'listing_count' => $count,
                'listing_breakdown' => $categoryByCity[$cityKey] ?? ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                'transaction_breakdown' => $transactionByCity[$cityKey] ?? ['sold' => 0, 'rented' => 0, 'leased' => 0],
            ];
        }

        $provinceData = array_values($provinces);

        foreach ($provinceData as &$province) {
            usort($province['cities'], function (array $a, array $b) {
                return [$b['listing_count'], $a['city_name']] <=> [$a['listing_count'], $b['city_name']];
            });
        }
        unset($province);

        usort($provinceData, function (array $a, array $b) use ($sortBy) {
            return match ($sortBy) {
                'listing_count' => [$b['listing_count'], $b['city_count'], $a['province_name']] <=> [$a['listing_count'], $a['city_count'], $b['province_name']],
                'for_sale'      => [$b['listing_breakdown']['for_sale'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_sale'], $a['city_count'], $b['province_name']],
                'for_rent'      => [$b['listing_breakdown']['for_rent'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['for_rent'], $a['city_count'], $b['province_name']],
                'foreclosure'   => [$b['listing_breakdown']['foreclosure'], $b['city_count'], $a['province_name']] <=> [$a['listing_breakdown']['foreclosure'], $a['city_count'], $b['province_name']],
                'sold'          => [$b['transaction_breakdown']['sold'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['sold'], $a['city_count'], $b['province_name']],
                'rented'        => [$b['transaction_breakdown']['rented'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['rented'], $a['city_count'], $b['province_name']],
                'leased'        => [$b['transaction_breakdown']['leased'], $b['city_count'], $a['province_name']] <=> [$a['transaction_breakdown']['leased'], $a['city_count'], $b['province_name']],
                'province_name' => [$a['province_name'], $b['listing_count']] <=> [$b['province_name'], $a['listing_count']],
                default         => [$b['city_count'], $b['listing_count'], $a['province_name']] <=> [$a['city_count'], $a['listing_count'], $b['province_name']],
            };
        });

        $totals = [
            'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0,
            'sold' => 0, 'rented' => 0, 'leased' => 0,
        ];
        foreach ($provinceData as $province) {
            $totals['for_sale']    += $province['listing_breakdown']['for_sale']     ?? 0;
            $totals['for_rent']    += $province['listing_breakdown']['for_rent']     ?? 0;
            $totals['foreclosure'] += $province['listing_breakdown']['foreclosure']  ?? 0;
            $totals['sold']        += $province['transaction_breakdown']['sold']     ?? 0;
            $totals['rented']      += $province['transaction_breakdown']['rented']   ?? 0;
            $totals['leased']      += $province['transaction_breakdown']['leased']   ?? 0;
        }

        return [
            'data' => $provinceData,
            'meta' => [
                'total_provinces'   => count($provinceData),
                // Each city belongs to exactly one province, so summing the
                // per-province city_count gives the unique city total.
                'total_cities'      => array_sum(array_column($provinceData, 'city_count')),
                'total_listings'    => array_sum(array_column($provinceData, 'listing_count')),
                'total_for_sale'    => $totals['for_sale'],
                'total_for_rent'    => $totals['for_rent'],
                'total_foreclosure' => $totals['foreclosure'],
                'total_sold'        => $totals['sold'],
                'total_rented'      => $totals['rented'],
                'total_leased'      => $totals['leased'],
                'sort_by'           => $sortBy,
            ],
        ];
    }

    /**
     * Status-level breakdown. Returns one row per properties.status, with the
     * listing count, category breakdown, and the top provinces for that status.
     */
    public function statusBreakdown(string $sortBy = 'listing_count'): array
    {
        // This breakdown only covers transaction statuses (Sold / Rented /
        // Leased). Active listings live elsewhere (province + category cards)
        // and aren't shown here so the section stays focused on outcomes.
        $statusCategoryRows = $this->baseListingQuery()
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->select(
                'properties.status as status',
                'categories.name as category_name',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('properties.status, categories.name')
            ->get();

        // Top provinces per status — order by listing count desc, keep top 5.
        $statusProvinceRows = $this->baseListingQuery()
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->whereNotNull(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'))
            ->select(
                'properties.status as status',
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) as province_id'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name'),
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('
                properties.status,
                COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id),
                COALESCE(project_provinces.name, property_provinces.name)
            ')
            ->orderBy('properties.status')
            ->orderByDesc('listing_count')
            ->get();

        // Visibility split per status — public vs private.
        $statusVisibilityRows = $this->baseListingQuery()
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->select(
                'properties.status as status',
                'listings.visibility as visibility',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('properties.status, listings.visibility')
            ->get();

        // Pivot into per-status maps.
        $statuses = [];
        foreach ($statusCategoryRows as $row) {
            $status = (string) ($row->status ?? 'unspecified');
            if (!isset($statuses[$status])) {
                $statuses[$status] = [
                    'status' => $status,
                    'listing_count' => 0,
                    'listing_breakdown' => ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                    'visibility_breakdown' => ['public' => 0, 'private' => 0],
                    'top_provinces' => [],
                ];
            }
            $count = (int) $row->listing_count;
            $statuses[$status]['listing_count'] += $count;
            $key = $this->categoryKey((string) $row->category_name);
            if ($key !== null) {
                $statuses[$status]['listing_breakdown'][$key] = $count;
            }
        }

        foreach ($statusVisibilityRows as $row) {
            $status = (string) ($row->status ?? 'unspecified');
            if (!isset($statuses[$status])) {
                continue;
            }
            $visibility = strtolower((string) ($row->visibility ?? 'public'));
            $bucket = $visibility === 'private' ? 'private' : 'public';
            $statuses[$status]['visibility_breakdown'][$bucket] += (int) $row->listing_count;
        }

        foreach ($statusProvinceRows as $row) {
            $status = (string) ($row->status ?? 'unspecified');
            if (!isset($statuses[$status])) {
                continue;
            }
            if (count($statuses[$status]['top_provinces']) >= 5) {
                continue;
            }
            $statuses[$status]['top_provinces'][] = [
                'province_id'   => (int) $row->province_id,
                'province_name' => (string) $row->province_name,
                'listing_count' => (int) $row->listing_count,
            ];
        }

        $statusData = array_values($statuses);

        // Sort: sold first, then rented, then leased (admin-friendly order).
        $priority = ['sold' => 0, 'rented' => 1, 'leased' => 2];
        usort($statusData, function (array $a, array $b) use ($sortBy, $priority) {
            return match ($sortBy) {
                'name'          => [$a['status'], $b['listing_count']] <=> [$b['status'], $a['listing_count']],
                'listing_count' => [$b['listing_count'], $a['status']] <=> [$a['listing_count'], $b['status']],
                default         => [
                    $priority[$a['status']] ?? 99,
                    -$a['listing_count'],
                    $a['status'],
                ] <=> [
                    $priority[$b['status']] ?? 99,
                    -$b['listing_count'],
                    $b['status'],
                ],
            };
        });

        $totalListings = array_sum(array_column($statusData, 'listing_count'));

        return [
            'data' => $statusData,
            'meta' => [
                'total_statuses' => count($statusData),
                'total_listings' => $totalListings,
                'sort_by'        => $sortBy,
            ],
        ];
    }

    /**
     * Paginated listing rows filtered to a single properties.status. Used by
     * the "Listings by Status" drawer drill-down. Supports optional category
     * + province filters.
     *
     * @param  array{
     *     page?:int, per_page?:int, category?:string,
     *     visibility?:string, province_id?:int|null
     * } $params
     */
    public function listingsForStatus(string $status, array $params): array
    {
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $category = (string) ($params['category'] ?? '');
        $visibility = strtolower((string) ($params['visibility'] ?? ''));
        $provinceId = isset($params['province_id']) ? (int) $params['province_id'] : null;

        $categoryFilter = match (strtolower($category)) {
            'for-sale'    => 'For Sale',
            'for-rent'    => 'For Rent',
            'foreclosure' => 'Foreclosure',
            default       => null,
        };
        $visibilityFilter = in_array($visibility, ['public', 'private'], true) ? $visibility : null;

        $base = $this->baseListingQuery()
            ->when($status === 'active', function ($q) {
                // 'active' matches both explicit 'active' and NULL status.
                $q->where(function ($qq) {
                    $qq->where('properties.status', 'active')
                        ->orWhereNull('properties.status');
                });
            }, function ($q) use ($status) {
                $q->where('properties.status', $status);
            })
            ->when($categoryFilter, fn ($q) => $q->where('categories.name', $categoryFilter))
            ->when($visibilityFilter, function ($q) use ($visibilityFilter) {
                // 'public' bucket is everything not flagged 'private' (covers
                // NULL / legacy values too). 'private' is a strict match.
                if ($visibilityFilter === 'private') {
                    $q->where('listings.visibility', 'private');
                } else {
                    $q->where(function ($qq) {
                        $qq->where('listings.visibility', '!=', 'private')
                            ->orWhereNull('listings.visibility');
                    });
                }
            })
            ->when(
                $provinceId !== null,
                fn ($q) => $q->where(
                    DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'),
                    $provinceId
                )
            );

        $totalCount = (clone $base)->count('listings.id');

        $rows = (clone $base)
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
                'properties.is_project',
                DB::raw('COALESCE(projects.name, properties.name) as property_name'),
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name'),
                DB::raw('categories.name as category_name')
            )
            ->orderByDesc('listings.updated_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $listings = $rows->map(function ($row) {
            // listings.featured_photo can be a JSON array string — normalize
            // to the first URL so the frontend <img src> works.
            $featured = $row->featured_photo;
            if (is_string($featured)) {
                $trimmed = trim($featured);
                if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '"')) {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        $featured = !empty($decoded[0]) ? $decoded[0] : null;
                    } elseif (is_string($decoded)) {
                        $featured = $decoded;
                    }
                }
            } elseif (is_array($featured)) {
                $featured = !empty($featured[0]) ? $featured[0] : null;
            }

            return [
                'id'              => (int) $row->id,
                'code'            => (string) $row->code,
                'name'            => (string) $row->listing_name,
                'slug'            => $row->slug,
                'price'           => $row->price,
                'category_name'   => $row->category_name,
                'property_status' => $row->property_status,
                'visibility'      => $row->visibility,
                'is_featured'     => (bool) $row->is_featured,
                'is_project'      => (bool) $row->is_project,
                'image'           => $featured,
                'property_name'   => $row->property_name,
                'city_name'       => $row->city_name,
                'province_name'   => $row->province_name,
                'created_at'      => $row->created_at,
                'updated_at'      => $row->updated_at,
            ];
        });

        return [
            'data' => $listings,
            'meta' => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($totalCount / $perPage)),
                'per_page'     => $perPage,
                'total'        => $totalCount,
                'status'       => $status,
                'category'     => $categoryFilter,
                'province_id'  => $provinceId,
            ],
        ];
    }
}
