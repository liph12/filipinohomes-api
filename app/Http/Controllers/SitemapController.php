<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Listing;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SitemapController extends Controller
{
    /**
     * Lightweight listing data for sitemap.xml
     */
    public function listings(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Listing::query()
            ->publiclyListed()
            ->select('id', 'slug', 'created_at', 'updated_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Lightweight agent data for sitemap.xml
     */
    public function agents(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Agent::query()
            ->select('id', 'first_name', 'middle_name', 'last_name', 'created_at', 'updated_at')
            // Only active agents are indexable — an inactive/resigned/deactivated
            // agent's profile leaves the sitemap (their listings are already
            // gated by Listing::scopePubliclyListed). Trashed agents are excluded
            // by the SoftDeletes global scope.
            ->where('status', 'active')
            ->has('listings')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Lightweight blog data for sitemap.xml
     */
    public function blogs(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Post::query()
            ->whereNotNull('published_at')
            ->select('id', 'slug', 'published_at', 'updated_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Listing images for image-sitemap.xml
     */
    public function listingImages(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 200), 500);

        $paginator = Listing::query()
            ->publiclyListed()
            ->select('id', 'slug', 'name', 'featured_photo', 'property_id', 'updated_at')
            ->with('property:id,photos')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Blog images for image-sitemap.xml
     */
    public function blogImages(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Post::query()
            ->whereNotNull('published_at')
            ->select('id', 'slug', 'title', 'featured_image', 'updated_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Listing counts grouped by city, province, category, and property type.
     * Used by the frontend sitemap to only include location URLs that have data.
     */
    public function locationCounts(): JsonResponse
    {
        $rows = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            // Agent-status gate: mirror Listing::scopePubliclyListed()'s
            // whereHas('agent', active) so an inactive/resigned/deactivated (or
            // soft-deleted) agent's listings leave these counts too. INNER JOIN
            // on the PK (agent_id is NOT NULL + FK) never fans out COUNT(*).
            ->join('agents', function ($j) {
                $j->on('agents.id', '=', 'listings.agent_id')
                  ->where('agents.status', 'active')
                  ->whereNull('agents.deleted_at');
            })
            // Effective city = reverse-geocoded map pin when present, else the
            // agent-picked barangay's city (address_id, 100% filled). Same
            // COALESCE semantics as the public `city_id` filter in
            // Listing::scopeFilter, so these counts equal on-page totals by
            // construction. barangays is a LEFT join: a geo-resolved property
            // without address_id must still count.
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', DB::raw('COALESCE(properties.geo_city_id, barangays.city_id)'))
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            // Mirror the live public listings query (Listing::publiclyListed()
            // ->filter()) so counts match on-page results: exclude soft-deleted
            // rows (raw DB::table bypasses the SoftDeletes trait) and do NOT
            // filter property.status (the public browse shows all statuses;
            // active_only is opt-in and not passed there).
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereNull('property_attributes.deleted_at')
            ->where('listings.visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('listings.verification_status')
                  ->orWhere('listings.verification_status', '!=', 'flagged');
            })
            ->select(
                'cities.id as city_id',
                'provinces.id as province_id',
                'cities.name as city',
                'provinces.name as province',
                'categories.name as category',
                'property_types.name as type',
                'property_subtypes.name as subtype',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('cities.id', 'provinces.id', 'cities.name', 'provinces.name', 'categories.name', 'property_types.name', 'property_subtypes.name')
            ->having    ('total', '>=', 1)
            ->get();

        return response()->json($rows);
    }

    /**
     * Per-cohort "affordable" price ceilings, read from the precomputed
     * modifier_price_thresholds table (refreshed daily by
     * `seo:compute-modifier-thresholds`). The frontend matches a cohort by the
     * same city/province slug it already builds for location pages and applies
     * `price_max = percentile_price` when rendering an affordable modifier page.
     */
    public function modifierThresholds(): JsonResponse
    {
        $rows = DB::table('modifier_price_thresholds')
            ->select(
                'modifier',
                'category',
                'type',
                'city',
                'province',
                'percentile_price',
                'sample_size'
            )
            ->orderBy('category')
            ->orderBy('type')
            ->orderBy('province')
            ->orderBy('city')
            ->get();

        return response()->json($rows);
    }

    /**
     * Post-filter listing counts per (modifier × category × type × city/province)
     * combo, gated at MODIFIER_MIN_LISTINGS so the modifier sitemap shard never
     * emits thin "Crawled - currently not indexed" pages. One flat list across all
     * v1 modifiers; the frontend builds /{cat}/{type}/{modifier}/in-{city-province}.
     *
     * Modifier → filter mapping (all backed by real, queryable columns):
     *   affordable      → listings.price <= cohort percentile (from thresholds table)
     *   furnished       → properties.furnishing_id = 1
     *   semi-furnished  → properties.furnishing_id = 2
     *   unfurnished     → properties.furnishing_id = 3
     *   best            → verification_status IN (verified, fully_verified)
     *                     (NOT is_featured — that flag is dormant site-wide)
     */
    public function queryCounts(): JsonResponse
    {
        $minListings = 10;
        $results = [];

        // --- affordable: join the precomputed thresholds, count at/below ceiling
        $affordable = $this->publiclyListedListingsJoin()
            ->join('modifier_price_thresholds as mpt', function ($j) {
                $j->on('mpt.category_id', '=', 'listings.category_id')
                  ->on('mpt.property_type_id', '=', 'property_types.id')
                  ->on('mpt.city_id', '=', 'cities.id')
                  ->where('mpt.modifier', '=', 'affordable');
            })
            ->whereColumn('listings.price', '<=', 'mpt.percentile_price')
            ->select(
                'categories.name as category',
                'property_types.name as type',
                'cities.name as city',
                'provinces.name as province',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('categories.name', 'property_types.name', 'cities.name', 'provinces.name')
            ->having('total', '>=', $minListings)
            ->get();
        foreach ($affordable as $r) {
            $results[] = $this->queryCountRow('affordable', $r);
        }

        // --- furnishing trio + best: simple per-cohort predicates
        $predicates = [
            'furnished'      => fn ($q) => $q->where('properties.furnishing_id', 1),
            'semi-furnished' => fn ($q) => $q->where('properties.furnishing_id', 2),
            'unfurnished'    => fn ($q) => $q->where('properties.furnishing_id', 3),
            'best'           => fn ($q) => $q->whereIn('listings.verification_status', ['verified', 'fully_verified']),
        ];

        foreach ($predicates as $modifier => $apply) {
            $query = $this->publiclyListedListingsJoin()
                ->select(
                    'categories.name as category',
                    'property_types.name as type',
                    'cities.name as city',
                    'provinces.name as province',
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('categories.name', 'property_types.name', 'cities.name', 'provinces.name')
                ->having('total', '>=', $minListings);
            $apply($query);

            foreach ($query->get() as $r) {
                $results[] = $this->queryCountRow($modifier, $r);
            }
        }

        // --- static price bands (under-1m/3m/5m): For Sale only. A band
        // that covers ~the whole cohort is a near-duplicate of the base
        // city page, so a row is only emitted when the band is a REAL
        // subset (≤85% of the unfiltered cohort). The frontend mirrors
        // this: band pages absent from this feed render noindex,follow
        // and are excluded from the modifier cross-link mesh.
        $bandCaps = [
            'under-1m' => 1_000_000,
            'under-3m' => 3_000_000,
            'under-5m' => 5_000_000,
        ];

        $baseTotals = [];
        $baseRows = $this->publiclyListedListingsJoin()
            ->where('categories.name', 'For Sale')
            ->select(
                'categories.name as category',
                'property_types.name as type',
                'cities.name as city',
                'provinces.name as province',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('categories.name', 'property_types.name', 'cities.name', 'provinces.name')
            ->get();
        foreach ($baseRows as $r) {
            $baseTotals["{$r->type}|{$r->city}|{$r->province}"] = (int) $r->total;
        }

        foreach ($bandCaps as $modifier => $cap) {
            $query = $this->publiclyListedListingsJoin()
                ->where('categories.name', 'For Sale')
                ->where('listings.price', '<=', $cap)
                ->select(
                    'categories.name as category',
                    'property_types.name as type',
                    'cities.name as city',
                    'provinces.name as province',
                    DB::raw('COUNT(*) as total')
                )
                ->groupBy('categories.name', 'property_types.name', 'cities.name', 'provinces.name')
                ->having('total', '>=', $minListings);

            foreach ($query->get() as $r) {
                $base = $baseTotals["{$r->type}|{$r->city}|{$r->province}"] ?? 0;
                if ($base > 0 && (int) $r->total > 0.85 * $base) {
                    continue; // band ≈ whole cohort → duplicate of the base page
                }
                $results[] = $this->queryCountRow($modifier, $r);
            }
        }

        return response()->json($results);
    }

    /**
     * Shape one query-count row for the frontend sitemap shard.
     */
    private function queryCountRow(string $modifier, $r): array
    {
        return [
            'modifier' => $modifier,
            'category' => $r->category,
            'type'     => $r->type,
            'city'     => $r->city,
            'province' => $r->province,
            'total'    => (int) $r->total,
        ];
    }

    /**
     * Join chain + publicly-listed predicate shared by the modifier query counts,
     * mirroring locationCounts() and the live Listing::publiclyListed()->filter()
     * query: visibility public, not flagged, not soft-deleted (listings /
     * properties / property_attributes), all statuses (the public browse does NOT
     * filter property.status — active_only is opt-in and not passed there).
     */
    private function publiclyListedListingsJoin()
    {
        return DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            // Agent-status gate: mirror Listing::scopePubliclyListed()'s
            // whereHas('agent', active) so an inactive/resigned/deactivated (or
            // soft-deleted) agent's listings leave these counts too. INNER JOIN
            // on the PK (agent_id is NOT NULL + FK) never fans out COUNT(*).
            ->join('agents', function ($j) {
                $j->on('agents.id', '=', 'listings.agent_id')
                  ->where('agents.status', 'active')
                  ->whereNull('agents.deleted_at');
            })
            // Effective city = COALESCE(geo pin, agent-picked barangay's city)
            // — same registry semantics as locationCounts and the public
            // city_id filter, so modifier/band counts match on-page results.
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', DB::raw('COALESCE(properties.geo_city_id, barangays.city_id)'))
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereNull('property_attributes.deleted_at')
            ->where('listings.visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('listings.verification_status')
                  ->orWhere('listings.verification_status', '!=', 'flagged');
            });
    }

    /**
     * Precomputed nearby-listing counts per (facility × category × type), read
     * from facility_listing_counts (refreshed daily by seo:compute-facility-counts).
     * Drives the "near {facility}" sitemap shard + SSG gating. Already gated at
     * the MIN_LISTINGS floor by the compute job.
     */
    public function facilityCounts(): JsonResponse
    {
        $rows = DB::table('facility_listing_counts')
            ->select(
                'facility_slug',
                'facility_name',
                'aliases',
                'facility_category',
                'city',
                'province',
                'category',
                'type',
                'total'
            )
            ->orderBy('facility_name')
            ->orderBy('category')
            ->orderBy('type')
            ->get()
            ->map(function ($r) {
                // Decode server-side so the frontend gets a clean string[] (the
                // search index unions these former-name tokens into the facility).
                $r->aliases = $r->aliases ? json_decode($r->aliases, true) : [];

                return $r;
            });

        return response()->json($rows);
    }

    /**
     * Precomputed public listing counts per effective barangay × category ×
     * property type (refreshed daily by `seo:compute-barangay-counts`).
     * Feeds the frontend barangay registry (src/lib/barangays.ts), the
     * barangay sitemap shard, and the page indexability floors — a table
     * read only, no live GROUP BY per request.
     */
    public function barangayCounts(): JsonResponse
    {
        $rows = DB::table('barangay_listing_counts')
            ->select(
                'barangay_id',
                'barangay',
                'city_id',
                'city',
                'province_id',
                'province',
                'category',
                'type',
                'total'
            )
            ->orderBy('city')
            ->orderBy('barangay')
            ->orderBy('category')
            ->orderBy('type')
            ->get();

        return response()->json($rows);
    }

    /**
     * Monthly market-stats snapshots (median/avg price, median ₱/sqm,
     * counts) per category × type × city|province [× bedroom], refreshed
     * daily by `seo:compute-market-stats`. Returns the two most recent
     * months so the frontend can render month-over-month movement once a
     * second snapshot exists. Feeds src/lib/marketStats.ts.
     *
     * `?v=` gates the province_urban/province_rural segment scopes: callers
     * below v2 only ever receive city/province rows, because older frontend
     * builds fold any non-city scope into the province bucket.
     */
    public function marketStats(Request $request): JsonResponse
    {
        $months = DB::table('market_stats')
            ->select('month')
            ->distinct()
            ->orderByDesc('month')
            ->limit(2)
            ->pluck('month');

        $rows = DB::table('market_stats')
            ->whereIn('month', $months)
            ->when((int) $request->input('v', 1) < 2, function ($q) {
                $q->whereIn('scope', ['city', 'province']);
            })
            ->select(
                'category',
                'type',
                'scope',
                'city_id',
                'city',
                'province_id',
                'province',
                'bedroom_count',
                'month',
                'listing_count',
                'median_price',
                'avg_price',
                'median_ppsqm',
                'ppsqm_count'
            )
            ->orderBy('month')
            ->orderBy('province')
            ->orderBy('city')
            ->get();

        return response()->json($rows);
    }

    /**
     * Full monthly history for ONE market-stats cohort (all-bedrooms rows
     * only), powering the trend chart on money pages. Province scope also
     * carries the urban/rural segment series. Kept separate from
     * marketStats() so that bulk two-month payload never grows as history
     * accrues.
     */
    public function marketStatsHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category'    => 'required|in:For Sale,For Rent',
            'type'        => 'required|string|max:64',
            'scope'       => 'required|in:city,province',
            'city_id'     => 'required_if:scope,city|integer',
            'province_id' => 'required_if:scope,province|integer',
            'months'      => 'nullable|integer',
        ]);

        $months = min(60, max(1, (int) ($validated['months'] ?? 36)));
        $cutoff = now()->startOfMonth()->subMonths($months - 1)->toDateString();
        $isCity = $validated['scope'] === 'city';

        $query = DB::table('market_stats')
            ->where('category', $validated['category'])
            ->where('type', $validated['type'])
            ->whereNull('bedroom_count')
            ->where('month', '>=', $cutoff)
            ->orderBy('month');

        if ($isCity) {
            $query->where('scope', 'city')->where('city_id', (int) $validated['city_id']);
        } else {
            $query->whereIn('scope', ['province', 'province_urban', 'province_rural'])
                ->where('province_id', (int) $validated['province_id']);
        }

        $rows = $query->get();

        // Cast numerics so JSON carries numbers, not the driver's decimal
        // strings.
        $point = fn ($r) => [
            'month'         => $r->month,
            'listing_count' => (int) $r->listing_count,
            'median_price'  => (float) $r->median_price,
            'avg_price'     => (float) $r->avg_price,
            'median_ppsqm'  => $r->median_ppsqm !== null ? (float) $r->median_ppsqm : null,
            'ppsqm_count'   => (int) $r->ppsqm_count,
        ];

        $overall = [];
        $urban = [];
        $rural = [];
        foreach ($rows as $r) {
            if ($r->scope === 'province_urban') {
                $urban[] = $point($r);
            } elseif ($r->scope === 'province_rural') {
                $rural[] = $point($r);
            } else {
                $overall[] = $point($r);
            }
        }

        $first = $rows->first();

        return response()->json([
            'cohort' => [
                'category'    => $validated['category'],
                'type'        => $validated['type'],
                'scope'       => $validated['scope'],
                'city_id'     => $isCity ? (int) $validated['city_id'] : null,
                'city'        => $first?->city,
                'province_id' => $isCity
                    ? ($first !== null ? (int) $first->province_id : null)
                    : (int) $validated['province_id'],
                'province'    => $first?->province,
            ],
            'overall' => $overall,
            'urban'   => $urban,
            'rural'   => $rural,
        ])->header('Cache-Control', 'public, max-age=21600');
    }

    /**
     * Active, geocoded facility registry for the frontend — slug -> coords used
     * to resolve a `near-{facility}` URL into the radius filter and on-page copy.
     */
    public function facilities(): JsonResponse
    {
        $rows = DB::table('facilities')
            ->where('is_active', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->select('slug', 'former_slugs', 'name', 'aliases', 'category', 'lat', 'lng', 'city', 'province')
            ->orderBy('name')
            ->get()
            ->map(function ($r) {
                // former_slugs powers the frontend's old-slug → 301 resolver;
                // aliases feeds the optional "formerly known as" page copy.
                $r->former_slugs = $r->former_slugs ? json_decode($r->former_slugs, true) : [];
                $r->aliases = $r->aliases ? json_decode($r->aliases, true) : [];

                return $r;
            });

        return response()->json($rows);
    }

    /**
     * Agent images for image-sitemap.xml
     */
    public function agentImages(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Agent::query()
            ->select('id', 'first_name', 'middle_name', 'last_name', 'avatar', 'updated_at')
            // Only active agents are indexable (matches the agents sitemap shard).
            ->where('status', 'active')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }
}
