<?php

namespace App\Services\Listing;

use App\Support\IslandMap;
use App\Support\RegionMap;
use Illuminate\Support\Facades\DB;

/**
 * Shared SQL building blocks for the admin "Listing Insights" services
 * (by-province, by-city, by-status, by-type). Counterpart to the Project
 * Insights services, but operates on every listing (no is_project filter), so
 * project units and standalone listings are treated equally. Counts are
 * listing-row counts.
 *
 * Per-request scope (agent/date/location) is stored on the instance and applied
 * centrally in baseListingQuery() so every sub-query of a single call stays
 * consistent. "Standard categories" = For Sale / For Rent / Foreclosure.
 */
abstract class ListingInsightsService
{
    protected const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];

    protected const TRANSACTION_STATUSES = ['sold', 'rented', 'leased'];

    /**
     * Per-request scoping for team-leader callers. When non-null, every
     * aggregation only counts listings whose `agent_id` is in this list —
     * the leader sees their team's footprint, not the whole platform.
     * Admins pass null (no scoping). An empty array means "no agents" and
     * intentionally yields zeroes.
     */
    protected ?array $agentIds = null;

    /**
     * Per-request date window (on listings.created_at). When set, every
     * aggregation only counts listings created within the range. Null means
     * "all-time". Applied centrally in baseListingQuery() so all sub-queries
     * stay consistent.
     */
    protected ?string $dateStart = null;

    protected ?string $dateEnd = null;

    /**
     * Optional province / city scope. When set, baseListingQuery() narrows to
     * the matching location (resolved via project or property → barangay).
     */
    protected ?int $provinceId = null;

    protected ?int $cityId = null;

    /**
     * Optional hierarchical scope from the Listing Insights map: a barangay,
     * or an island / region. island & region resolve to a province-id whitelist
     * (via IslandMap / RegionMap) and only apply when neither province nor city
     * is set — the more specific province/city scope always wins.
     */
    protected ?int $barangayId = null;

    protected ?string $island = null;

    protected ?string $region = null;

    /** Province IDs precomputed for a region/island scope (null = no such scope). */
    protected ?array $scopeProvinceIds = null;

    /**
     * Viewport bounding box (Listing Insights map clusters). When all four are
     * set, baseListingQuery() restricts to listings whose geo_coordinates fall
     * on-screen, so panning/zooming re-clusters to what's visible.
     */
    protected ?float $minLat = null;

    protected ?float $maxLat = null;

    protected ?float $minLng = null;

    protected ?float $maxLng = null;

    /** Explicit cluster level (island|region|province|city|barangay) — map only. */
    protected ?string $levelOverride = null;

    /** Cached [province_id => province_name], loaded once per request. */
    private ?array $provinceNamesCache = null;

    /**
     * Base join chain — listings → properties → location resolution.
     * Every aggregation query layers on top of this closure.
     */
    protected function baseListingQuery()
    {
        $query = DB::table('listings')
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
            // Exclude listings whose property was marked deleted (status, not soft-delete).
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES);

        if ($this->agentIds !== null) {
            $query->whereIn('listings.agent_id', $this->agentIds);
        }

        if ($this->dateStart !== null && $this->dateStart !== '') {
            $query->where('listings.created_at', '>=', $this->dateStart.' 00:00:00');
        }
        if ($this->dateEnd !== null && $this->dateEnd !== '') {
            $query->where('listings.created_at', '<=', $this->dateEnd.' 23:59:59');
        }

        if ($this->provinceId !== null) {
            $query->where(DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'), $this->provinceId);
        }
        if ($this->cityId !== null) {
            $query->where(DB::raw('COALESCE(projects.city_id, property_cities.id)'), $this->cityId);
        }

        if ($this->barangayId !== null) {
            // Listing insights has no geo_barangay join; the agent-picked
            // barangay is properties.address_id. (Geo-first barangay is a
            // Phase-2 enhancement, mirroring the inquiry base.)
            $query->where('properties.address_id', $this->barangayId);
        }
        if ($this->scopeProvinceIds !== null) {
            // Empty list (region/island with no provinces) → no rows, intentionally.
            $query->whereIn(
                DB::raw('COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)'),
                $this->scopeProvinceIds ?: [0]
            );
        }

        // Viewport bounding box (map clusters) — restrict to on-screen pins.
        if ($this->minLat !== null && $this->maxLat !== null && $this->minLng !== null && $this->maxLng !== null) {
            $lat = $this->geoLat();
            $lng = $this->geoLng();
            $query->whereRaw("({$lat}) BETWEEN ? AND ?", [$this->minLat, $this->maxLat])
                ->whereRaw("({$lng}) BETWEEN ? AND ?", [$this->minLng, $this->maxLng]);
        }

        return $query;
    }

    /** [province_id => name] from the provinces table (cached, ~82 rows). */
    protected function provinceNames(): array
    {
        if ($this->provinceNamesCache === null) {
            $this->provinceNamesCache = DB::table('provinces')->pluck('name', 'id')->all();
        }

        return $this->provinceNamesCache;
    }

    /**
     * Turn a region/island scope into a province-id whitelist. region wins over
     * island; both are ignored once an explicit province/city is set (the more
     * specific scope already narrows the query). Call after setting the scope
     * props and before the first baseListingQuery().
     */
    protected function resolveScopeProvinceIds(): void
    {
        if ($this->provinceId !== null || $this->cityId !== null) {
            $this->scopeProvinceIds = null;

            return;
        }
        if ($this->region !== null && $this->region !== '') {
            $this->scopeProvinceIds = RegionMap::provinceIdsForRegion($this->provinceNames(), $this->region);
        } elseif ($this->island !== null && $this->island !== '') {
            $this->scopeProvinceIds = IslandMap::provinceIdsForIsland($this->provinceNames(), $this->island);
        } else {
            $this->scopeProvinceIds = null;
        }
    }

    /** Numeric lat/lng extracted from the geo_coordinates JSON column. */
    protected function geoLat(): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
    }

    protected function geoLng(): string
    {
        return "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lng')) AS DECIMAL(12,8))";
    }

    /**
     * Hydrate per-request scope from a validated filter array (used by the map
     * cluster service). Mirrors the positional setters the other services use,
     * but keyed — date_start/date_end, province_id, city_id, barangay_id, island,
     * region, level, and the min/max lat/lng viewport box.
     */
    public function configure(array $filters, ?array $agentIds = null): static
    {
        $this->agentIds = $agentIds;
        $this->dateStart = isset($filters['date_start']) && $filters['date_start'] !== '' ? (string) $filters['date_start'] : null;
        $this->dateEnd = isset($filters['date_end']) && $filters['date_end'] !== '' ? (string) $filters['date_end'] : null;
        $this->provinceId = isset($filters['province_id']) ? (int) $filters['province_id'] : null;
        $this->cityId = isset($filters['city_id']) ? (int) $filters['city_id'] : null;
        $this->barangayId = isset($filters['barangay_id']) ? (int) $filters['barangay_id'] : null;
        $this->island = isset($filters['island']) && $filters['island'] !== '' ? (string) $filters['island'] : null;
        $this->region = isset($filters['region']) && $filters['region'] !== '' ? (string) $filters['region'] : null;
        $this->levelOverride = isset($filters['level']) && $filters['level'] !== '' ? (string) $filters['level'] : null;
        $this->minLat = isset($filters['min_lat']) ? (float) $filters['min_lat'] : null;
        $this->maxLat = isset($filters['max_lat']) ? (float) $filters['max_lat'] : null;
        $this->minLng = isset($filters['min_lng']) ? (float) $filters['min_lng'] : null;
        $this->maxLng = isset($filters['max_lng']) ? (float) $filters['max_lng'] : null;

        $this->resolveScopeProvinceIds();

        return $this;
    }

    protected function categoryKey(string $name): ?string
    {
        return match ($name) {
            'For Sale' => 'for_sale',
            'For Rent' => 'for_rent',
            'Foreclosure' => 'foreclosure',
            default => null,
        };
    }

    /**
     * Constrain a query to listings whose property carries ATS attachment files.
     * Uses the indexed generated column properties.has_ats_files (see the
     * add_insights_indexes migration) so this is index-backed, not a per-row
     * JSON_LENGTH scan.
     */
    protected function withAtsAttachments($query)
    {
        return $query->where('properties.has_ats_files', 1);
    }
}
