<?php

namespace App\Services\Project;

use Illuminate\Support\Facades\DB;

/**
 * "Projects by Name" — the paginated, deduped one-row-per-project listing with
 * full stat breakdowns, plus the single-project drill-down used by the detail
 * drawer. Delegates the leaderboard/aggregates block to ProjectLeaderboardService.
 */
class ProjectByNameService extends ProjectInsightsService
{
    public function __construct(
        private ProjectLeaderboardService $leaderboards,
    ) {
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
        $provinceId = isset($params['province_id']) ? (int) $params['province_id'] : null;
        $cityId     = isset($params['city_id']) ? (int) $params['city_id'] : null;
        $dateStart  = !empty($params['date_start']) ? (string) $params['date_start'] . ' 00:00:00' : null;
        $dateEnd    = !empty($params['date_end']) ? (string) $params['date_end'] . ' 23:59:59' : null;

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
                    ->when($dateStart, fn ($q) => $q->where('listings.created_at', '>=', $dateStart))
                    ->when($dateEnd, fn ($q) => $q->where('listings.created_at', '<=', $dateEnd))
                    ->distinct(),
                'project_listing_properties',
                fn ($join) => $join->on('project_listing_properties.property_id', '=', 'properties.id')
            );

        // Optional province / city scope (resolved via project or property location).
        if ($provinceId !== null) {
            $base->whereRaw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id) = ?', [$provinceId]);
        }
        if ($cityId !== null) {
            $base->whereRaw('COALESCE(projects.city_id, property_cities.id) = ?', [$cityId]);
        }

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
            ->when($dateStart, fn ($q) => $q->where('listings.created_at', '>=', $dateStart))
            ->when($dateEnd, fn ($q) => $q->where('listings.created_at', '<=', $dateEnd))
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
            ->leftJoin('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->select(
                DB::raw("{$projectKey} as project_key"),
                'properties.project_id',
                DB::raw('COALESCE(projects.name, properties.name) as project_name'),
                'projects.slug as project_slug',
                'projects.featured_photo',
                'property_subtypes.name as subtype_name',
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
                property_subtypes.name,
                COALESCE(project_cities.name, property_cities.name),
                COALESCE(project_provinces.name, property_provinces.name)
            ");

        match ($sortBy) {
            'name'  => $aggregated->orderByRaw('COALESCE(projects.name, properties.name) ASC'),
            default => $aggregated->orderByDesc('total_listings'),
        };

        // 3) Fetch all per-key rows, then collapse to UNIQUE project name —
        //    same-named projects (incl. orphan duplicates) merge into one row
        //    with summed counts + combined subtypes. Manual pagination because
        //    group-by + dedupe doesn't paginate cleanly in SQL.
        $allRows = $aggregated->get();

        $byName = [];
        foreach ($allRows as $r) {
            $name = (string) $r->project_name;
            if (!isset($byName[$name])) {
                $byName[$name] = (object) [
                    'project_key' => (string) $r->project_key,
                    'project_id' => $r->project_id !== null ? (int) $r->project_id : null,
                    'project_name' => $name,
                    'project_slug' => $r->project_slug,
                    'featured_photo' => $r->featured_photo,
                    'city_name' => $r->city_name,
                    'province_name' => $r->province_name,
                    'total_listings' => 0,
                    'for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0,
                    'sold' => 0, 'rented' => 0, 'leased' => 0,
                    'subtypes' => [],   // subtype name => listing count
                    '_rep' => -1,       // representative = largest contributing project
                ];
            }
            $agg = $byName[$name];
            $count = (int) $r->total_listings;
            $agg->total_listings += $count;
            $agg->for_sale       += (int) $r->for_sale;
            $agg->for_rent       += (int) $r->for_rent;
            $agg->foreclosure    += (int) $r->foreclosure;
            $agg->sold           += (int) $r->sold;
            $agg->rented         += (int) $r->rented;
            $agg->leased         += (int) $r->leased;

            $st = trim((string) ($r->subtype_name ?? ''));
            if ($st !== '') {
                $agg->subtypes[$st] = ($agg->subtypes[$st] ?? 0) + $count;
            }

            // Pick the biggest sub-project as the representative for city / key.
            if ($count > $agg->_rep) {
                $agg->_rep = $count;
                $agg->project_key = (string) $r->project_key;
                $agg->project_id = $r->project_id !== null ? (int) $r->project_id : null;
                $agg->project_slug = $r->project_slug;
                $agg->featured_photo = $r->featured_photo;
                $agg->city_name = $r->city_name;
                $agg->province_name = $r->province_name;
            }
        }

        $merged = array_values($byName);
        usort($merged, function ($a, $b) use ($sortBy) {
            return match ($sortBy) {
                'name'         => strcasecmp($a->project_name, $b->project_name),
                'transactions' => (($b->sold + $b->rented + $b->leased) <=> ($a->sold + $a->rented + $a->leased)) ?: ($b->total_listings <=> $a->total_listings),
                'subtypes'     => (count($b->subtypes) <=> count($a->subtypes)) ?: ($b->total_listings <=> $a->total_listings),
                default        => $b->total_listings <=> $a->total_listings,
            };
        });

        $total  = count($merged);
        $sliced = array_slice($merged, ($page - 1) * $perPage, $perPage);

        $rows = array_map(function ($row) {
            arsort($row->subtypes);
            return [
                'project_key'           => $row->project_key,
                'project_id'            => $row->project_id,
                'project_name'          => $row->project_name,
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
                'subtypes' => array_map(
                    fn ($name, $count) => ['name' => $name, 'count' => $count],
                    array_keys($row->subtypes),
                    array_values($row->subtypes),
                ),
            ];
        }, $sliced);

        $lastPage = max(1, (int) ceil($total / $perPage));

        // 4) Aggregates across ALL filtered rows — powers the dashboard's
        //    insights strip + Top 50 leaderboards. Stays accurate as the
        //    user paginates.
        $aggregates = $this->leaderboards->build($allRows, $total);

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
