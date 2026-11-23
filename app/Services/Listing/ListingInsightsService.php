<?php

namespace App\Services\Listing;

use App\Services\Reports\Executive\ReportRange;
use App\Support\IslandMap;
use App\Support\RegionMap;
use Illuminate\Support\Carbon;
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
 * consistent. "Standard categories" = For Sale / For Rent / Foreclosed.
 */
abstract class ListingInsightsService
{
    protected const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosed'];

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
     * Per-request date window, as Asia/Manila calendar days (Y-m-d). When set,
     * every aggregation only counts what falls inside it. Null means
     * "all-time". Applied centrally in applyDateScope() so all sub-queries
     * stay consistent.
     */
    protected ?string $dateStart = null;

    protected ?string $dateEnd = null;

    /**
     * What the window dates: 'created' (listings.created_at — listings ADDED in
     * the window; the default) or 'deal' (properties.status_change_date — deals
     * CLOSED in the window, as the Executive Report counts them). Every entry
     * point that fills in the window sets it with the window, so a reused
     * instance never carries it to the next call.
     */
    protected string $dateBasis = 'created';

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
     * City IDs for a scope that is SEVERAL `cities` rows at once (null = no
     * such scope). Mirror of $scopeProvinceIds one level down: the Listings
     * Heatmap's barangay tier scopes to a whole city GROUP (the duplicate rows
     * the `cities` table carries for one town), and $cityId filters on one id.
     * Applied on the same COALESCE(projects.city_id, property_cities.id) chain
     * as $cityId, so the barangay rows sum to the city row by construction.
     */
    protected ?array $scopeCityIds = null;

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
        $query = $this->joinLocation($this->coreListingQuery());
        $this->applyLocationFilters($query);

        return $query;
    }

    /**
     * Same rows as baseListingQuery(), minus the location joins when nothing in
     * the scope needs them. Those joins are LEFT joins on primary keys, so they
     * never add or drop a listing: a count that doesn't group by place is the
     * same number with six fewer joins.
     */
    protected function slimListingQuery()
    {
        $query = $this->coreListingQuery();
        if ($this->needsLocationJoins()) {
            $this->joinLocation($query);
        }
        $this->applyLocationFilters($query);

        return $query;
    }

    /**
     * listings ⋈ categories ⋈ properties: what counts as a listing at all, the
     * team-leader agent scope and the date window. No location yet.
     */
    protected function coreListingQuery()
    {
        $query = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            // Exclude listings whose property was marked deleted (status, not soft-delete).
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES);

        if ($this->agentIds !== null) {
            $query->whereIn('listings.agent_id', $this->agentIds);
        }

        $this->applyDateScope($query);

        return $query;
    }

    /** The location chain: project path first, else property → barangay → city → province. */
    protected function joinLocation($query)
    {
        return $query
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('cities as project_cities', 'project_cities.id', '=', 'projects.city_id')
            ->leftJoin('provinces as project_provinces', 'project_provinces.id', '=', 'projects.prov_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->leftJoin('provinces as property_provinces', 'property_provinces.id', '=', 'property_cities.province_id');
    }

    /** Whether any active filter reads the joined location tables (barangay and viewport read `properties` only). */
    protected function needsLocationJoins(): bool
    {
        return $this->provinceId !== null
            || $this->cityId !== null
            || $this->scopeProvinceIds !== null
            || $this->scopeCityIds !== null;
    }

    /**
     * The window as Manila days → half-open UTC instants, so "Nov 1" means
     * Nov 1 00:00 Manila, not the UTC day eight hours off it. No date function
     * wraps the column, so the comparison stays index-friendly.
     */
    protected function scopeRange(): ?ReportRange
    {
        return ReportRange::lenient($this->dateStart, $this->dateEnd);
    }

    /**
     * The window as the response reports it: its days, and the period before it
     * that a "change" compares against. Null for all time. An open start has no
     * first day to show, and no previous period (nothing precedes "the beginning").
     *
     * @return array{start: ?string, end: string, days: ?int, previous: ?array{start: string, end: string}}|null
     */
    protected function rangeMeta(): ?array
    {
        return $this->describeRange($this->scopeRange());
    }

    /**
     * rangeMeta() for a window the service holds itself — Demand dates events,
     * not listings, so its listing scope carries no window of its own.
     *
     * @return array{start: ?string, end: string, days: ?int, previous: ?array{start: string, end: string}}|null
     */
    protected function describeRange(?ReportRange $range): ?array
    {
        if ($range === null) {
            return null;
        }

        $previous = $range->hasPrevious() ? $range->previous() : null;

        return [
            'start' => $range->openStart ? null : $range->start,
            'end' => $range->end,
            'days' => $range->openStart || $range->isInverted() ? null : $range->days(),
            'previous' => $previous === null ? null : ['start' => $previous->start, 'end' => $previous->end],
        ];
    }

    /**
     * Run $query with the window swapped for $window (same place, team and
     * basis), then put the caller's own window back — also when it throws.
     * For the "period before" counts that sit beside a window's own.
     *
     * @template T
     *
     * @param  \Closure(): T  $query
     * @return T
     */
    protected function withWindow(ReportRange $window, \Closure $query): mixed
    {
        [$start, $end] = [$this->dateStart, $this->dateEnd];
        $this->dateStart = $window->start;
        $this->dateEnd = $window->end;

        try {
            return $query();
        } finally {
            [$this->dateStart, $this->dateEnd] = [$start, $end];
        }
    }

    protected function applyDateScope($query): void
    {
        $range = $this->scopeRange();
        if ($range === null) {
            return;
        }

        if ($this->dateBasis === 'deal') {
            // A DATE column: the same half-open shape on whole days.
            $query->where('properties.status_change_date', '>=', $range->start)
                ->where('properties.status_change_date', '<', $range->endExclusiveDay());

            return;
        }

        $query->where('listings.created_at', '>=', $range->fromUtc())
            ->where('listings.created_at', '<', $range->toUtc());
    }

    /** Province / city / barangay / island / region scope and the viewport box. */
    protected function applyLocationFilters($query): void
    {
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
        if ($this->scopeCityIds !== null) {
            // Empty list → no rows, intentionally (same contract as above).
            $query->whereIn(DB::raw($this->cityIdExpr()), $this->scopeCityIds ?: [0]);
        }

        // Viewport bounding box (map clusters) — restrict to on-screen pins.
        if ($this->minLat !== null && $this->maxLat !== null && $this->minLng !== null && $this->maxLng !== null) {
            $lat = $this->geoLat();
            $lng = $this->geoLng();
            $query->whereRaw("({$lat}) BETWEEN ? AND ?", [$this->minLat, $this->maxLat])
                ->whereRaw("({$lng}) BETWEEN ? AND ?", [$this->minLng, $this->maxLng]);
        }
    }

    /**
     * Location resolution expressions — project path first, else property →
     * barangay → city → province. Deliberately NO geo-first join, so every
     * service that aggregates by area produces the same numbers as the
     * "By Province" / "By City" tabs.
     *
     * These live on the base class (rather than on whichever subclass needed
     * them first) because the Geographic Map bubbles and the Listings Heatmap
     * choropleth MUST agree to the listing: two copies of a COALESCE chain is
     * two numbers the admin has to reconcile by hand the day one of them is
     * edited.
     */
    protected function provinceIdExpr(): string
    {
        return 'COALESCE(projects.prov_id, project_cities.province_id, property_cities.province_id)';
    }

    protected function provinceNameExpr(): string
    {
        return 'COALESCE(project_provinces.name, property_provinces.name)';
    }

    protected function cityIdExpr(): string
    {
        return 'COALESCE(projects.city_id, property_cities.id)';
    }

    protected function cityNameExpr(): string
    {
        return 'COALESCE(project_cities.name, property_cities.name)';
    }

    /**
     * Per-category listing counts for a grouped query. Same three standard
     * categories as STANDARD_CATEGORIES, in the response key order the
     * frontend expects (for_sale / for_rent / foreclosure).
     */
    protected function categorySelects(): array
    {
        return [
            DB::raw("SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale"),
            DB::raw("SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent"),
            DB::raw("SUM(CASE WHEN categories.name = 'Foreclosed' THEN 1 ELSE 0 END) as foreclosure"),
        ];
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

    /**
     * SQL that differs between production (MySQL) and the in-memory SQLite the
     * tests run on. Kept in these few helpers so no query carries its own
     * driver switch.
     */
    protected function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * The Asia/Manila calendar day of a UTC timestamp column — for SELECT and
     * GROUP BY only, never WHERE (the window is bound as instants, see
     * applyDateScope()). A fixed +8 shift: the Philippines has no daylight time.
     */
    protected function manilaDayExpr(string $column): string
    {
        return $this->isSqlite()
            ? "date({$column}, '+8 hours')"
            : "DATE(CONVERT_TZ({$column}, '+00:00', '+08:00'))";
    }

    /** Element count of a JSON array column (NULL for NULL). */
    protected function jsonLengthExpr(string $column): string
    {
        return $this->isSqlite() ? "json_array_length({$column})" : "JSON_LENGTH({$column})";
    }

    /** Character (not byte) length of a text column. */
    protected function charLengthExpr(string $column): string
    {
        return $this->isSqlite() ? "LENGTH({$column})" : "CHAR_LENGTH({$column})";
    }

    /**
     * A Y-m-d day as the first day of its bucket: the day itself, its week
     * (weeks start on Sunday, as in Agent Insights) or its month. Folding in
     * PHP keeps the SQL to one grouped pass per day on either driver.
     */
    protected function foldDay(string $day, string $granularity): string
    {
        return match ($granularity) {
            'month' => substr($day, 0, 7).'-01',
            'week' => Carbon::parse($day)->startOfWeek(Carbon::SUNDAY)->format('Y-m-d'),
            default => $day,
        };
    }

    /** A window of up to this many days reads best day by day; longer or open-ended ones read by month. */
    protected const AUTO_DAILY_DAYS = 62;

    /** Longest zero-filled series (about three years of days); a longer one is returned without filler. */
    protected const MAX_FILLED_BUCKETS = 1100;

    /** Days for a short, closed window; months for a long or open-ended one. */
    protected function autoGranularity(?ReportRange $range): string
    {
        if ($range === null || $range->openStart || $range->isInverted()) {
            return 'month';
        }

        return $range->days() <= self::AUTO_DAILY_DAYS ? 'day' : 'month';
    }

    /**
     * The first day of every bucket from $from to $to, in order — or none when
     * that would run past the filler cap.
     *
     * @return list<string>
     */
    protected function bucketKeys(string $from, string $to, string $granularity): array
    {
        $cursor = Carbon::parse($this->foldDay($from, $granularity));
        $last = $this->foldDay($to, $granularity);
        $keys = [];

        while ($cursor->format('Y-m-d') <= $last) {
            $keys[] = $cursor->format('Y-m-d');
            if (count($keys) > self::MAX_FILLED_BUCKETS) {
                return [];
            }
            $cursor = match ($granularity) {
                'month' => $cursor->addMonthNoOverflow(),
                'week' => $cursor->addWeek(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }

    /** Numeric lat/lng extracted from the geo_coordinates JSON column. */
    protected function geoLat(): string
    {
        return $this->isSqlite()
            ? "CAST(json_extract(properties.geo_coordinates, '$.lat') AS REAL)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
    }

    protected function geoLng(): string
    {
        return $this->isSqlite()
            ? "CAST(json_extract(properties.geo_coordinates, '$.lng') AS REAL)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lng')) AS DECIMAL(12,8))";
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
        $this->dateBasis = ($filters['date_basis'] ?? 'created') === 'deal' ? 'deal' : 'created';
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
        // Not a filter key: only the heatmap sets it, after configure().
        $this->scopeCityIds = null;

        $this->resolveScopeProvinceIds();

        return $this;
    }

    protected function categoryKey(string $name): ?string
    {
        return match ($name) {
            'For Sale' => 'for_sale',
            'For Rent' => 'for_rent',
            'Foreclosed' => 'foreclosure',
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
