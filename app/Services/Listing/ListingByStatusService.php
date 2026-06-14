<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * "Listings by Status" — the per-status breakdown panel plus the paginated
 * drawer drill-down listing every row in a given properties.status.
 */
class ListingByStatusService extends ListingInsightsService
{
    /**
     * Status-level breakdown. Returns one row per properties.status, with the
     * listing count, category breakdown, and the top provinces for that status.
     */
    public function statusBreakdown(
        string $sortBy = 'listing_count',
        ?array $agentIds = null,
        ?string $dateStart = null,
        ?string $dateEnd = null,
        ?int $provinceId = null,
        ?int $cityId = null,
        string $groupBy = 'province'
    ): array {
        $this->agentIds  = $agentIds;
        $this->dateStart = $dateStart;
        $this->dateEnd   = $dateEnd;

        $groupBy = $groupBy === 'city' ? 'city' : 'province';

        // Location resolution expressions shared by the filter + grouping.
        $provIdExpr  = 'COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)';
        $cityIdExpr  = 'COALESCE(projects.city_id, property_cities.id)';
        $locIdExpr   = $groupBy === 'city' ? $cityIdExpr : $provIdExpr;
        $locNameExpr = $groupBy === 'city'
            ? 'COALESCE(project_cities.name, property_cities.name)'
            : 'COALESCE(project_provinces.name, property_provinces.name)';

        // Apply the optional province/city filter to any status query.
        $applyLoc = function ($q) use ($provinceId, $cityId, $provIdExpr, $cityIdExpr) {
            if ($provinceId !== null) {
                $q->where(DB::raw($provIdExpr), $provinceId);
            }
            if ($cityId !== null) {
                $q->where(DB::raw($cityIdExpr), $cityId);
            }
            return $q;
        };

        // This breakdown covers all listings whose transaction status is one of
        // Sold / Rented / Leased, optionally scoped by date + province/city.
        $statusCategoryRows = $applyLoc($this->baseListingQuery())
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->select(
                'properties.status as status',
                'categories.name as category_name',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw('properties.status, categories.name')
            ->get();

        // Category mix per (status, location) — also feeds the location list
        // itself (its total = sum of categories), so no separate location query.
        $statusLocationCategoryRows = $applyLoc($this->baseListingQuery())
            ->whereIn('properties.status', self::TRANSACTION_STATUSES)
            ->whereNotNull(DB::raw($locIdExpr))
            ->select(
                'properties.status as status',
                DB::raw("$locIdExpr as location_id"),
                DB::raw("$locNameExpr as location_name"),
                'categories.name as category_name',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupByRaw("properties.status, $locIdExpr, $locNameExpr, categories.name")
            ->get();

        // Visibility split per status — public vs private.
        $statusVisibilityRows = $applyLoc($this->baseListingQuery())
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
                    'locations' => [],
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

        // Aggregate the (status, location, category) rows into one entry per
        // (status, location): name + total (sum of categories) + category mix.
        $locationAgg = [];
        foreach ($statusLocationCategoryRows as $row) {
            $status = (string) ($row->status ?? 'unspecified');
            $locId = (int) $row->location_id;
            $key = $status . '|' . $locId;
            if (!isset($locationAgg[$key])) {
                $locationAgg[$key] = [
                    'status' => $status,
                    'location_id' => $locId,
                    'location_name' => (string) $row->location_name,
                    'listing_count' => 0,
                    'listing_breakdown' => ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                ];
            }
            $count = (int) $row->listing_count;
            $locationAgg[$key]['listing_count'] += $count;
            $ck = $this->categoryKey((string) $row->category_name);
            if ($ck !== null) {
                $locationAgg[$key]['listing_breakdown'][$ck] += $count;
            }
        }

        foreach ($locationAgg as $loc) {
            $status = $loc['status'];
            if (!isset($statuses[$status])) {
                continue;
            }
            $count = $loc['listing_count'];
            $statuses[$status]['locations'][] = [
                'location_id'   => $loc['location_id'],
                'location_name' => $loc['location_name'],
                'listing_count' => $count,
                'listing_breakdown' => $loc['listing_breakdown'],
                // Status is fixed per card — the location's transaction mix is just this status.
                'transaction_breakdown' => [
                    'sold'   => $status === 'sold' ? $count : 0,
                    'rented' => $status === 'rented' ? $count : 0,
                    'leased' => $status === 'leased' ? $count : 0,
                ],
            ];
        }

        // Aggregated from an unordered map — order each status's list desc.
        foreach ($statuses as &$st) {
            usort($st['locations'], fn ($a, $b) => $b['listing_count'] <=> $a['listing_count']);
        }
        unset($st);

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
                'group_by'       => $groupBy,
                'province_id'    => $provinceId,
                'city_id'        => $cityId,
                'date_start'     => $this->dateStart,
                'date_end'       => $this->dateEnd,
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
    public function listingsForStatus(string $status, array $params, ?array $agentIds = null): array
    {
        $this->agentIds = $agentIds;
        $page     = max(1, (int) ($params['page'] ?? 1));
        $perPage  = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $category = (string) ($params['category'] ?? '');
        $visibility = strtolower((string) ($params['visibility'] ?? ''));
        $provinceId = isset($params['province_id']) ? (int) $params['province_id'] : null;
        $cityId     = isset($params['city_id']) ? (int) $params['city_id'] : null;

        // Province / city / date scope are applied centrally in baseListingQuery().
        $this->provinceId = $provinceId;
        $this->cityId     = $cityId;
        $this->dateStart  = isset($params['date_start']) && $params['date_start'] !== '' ? (string) $params['date_start'] : null;
        $this->dateEnd    = isset($params['date_end']) && $params['date_end'] !== '' ? (string) $params['date_end'] : null;

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
                // 'all' = every transaction status (Sold / Rented / Leased).
                if ($status === 'all') {
                    $q->whereIn('properties.status', self::TRANSACTION_STATUSES);
                } else {
                    $q->where('properties.status', $status);
                }
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
            });

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
                'city_id'      => $cityId,
            ],
        ];
    }
}
