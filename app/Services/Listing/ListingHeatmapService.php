<?php

namespace App\Services\Listing;

use App\Support\BarangayNameMatcher;
use App\Support\CityGroup;
use App\Support\CityNameMatcher;
use App\Support\ProvinceCanonicalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Listings Heatmap — one shaded row per province, per city, or per barangay
 * for the admin choropleth page. Counterpart to {@see ListingClusterService}:
 * same listing definition, same COALESCE location chain (both inherited from
 * {@see ListingInsightsService}), but it answers "how many" per AREA instead of
 * "where is the bubble".
 *
 * Three tiers, one shape:
 *
 *   province  — nationwide, or one canonical province.
 *   city      — nationwide, or the towns of one canonical province group.
 *   barangay  — the barangays of ONE city group (`city_id` is mandatory; a
 *               nationwide barangay tier would be 42k rows nobody asked for).
 *
 * Parity is the contract between the tiers: the barangay rows of a city MUST
 * sum to that city's row at the city tier, because the admin drills from one
 * into the other and reads the two numbers side by side. Both are computed
 * on the same base query with the same COALESCE chain; the barangay tier
 * scopes by the city GROUP (every `cities` row that is the same town, see
 * {@see CityGroup}) exactly as the city tier folds those rows into one. Only
 * a listing whose project sits in one city while its address barangay sits
 * in another could break the identity, and today none does.
 *
 * Four things here are deliberate and easy to break:
 *
 * 1. ONE grouped query. The page shows 3 categories × 8 measures (all-time,
 *    three "new" windows, the seven days before the last seven, and the three
 *    transaction statuses) and the user flips between them with zero network,
 *    so all 32 numbers per area — every measure overall and per category — are
 *    computed in a single pass of SUM(CASE WHEN …) columns rather than 32
 *    queries or a date-filtered call per window.
 *
 * 2. The time windows are computed in PHP and BOUND AS PARAMETERS. The app
 *    timezone is UTC, `listings.created_at` is UTC, and the MySQL session
 *    timezone on the local/prod boxes is +08 — so CURDATE()/NOW()/INTERVAL in
 *    SQL would silently answer a different question depending on who asks. On
 *    top of that the test suite runs on sqlite, which has none of MySQL's date
 *    functions. "Today" means the Asia/Manila calendar day, converted to UTC
 *    once, here.
 *
 * 3. Every area in scope is SEEDED with zeros before the query rows are
 *    folded in. Only ~60 of the 80 canonical provinces hold a listing, and
 *    most of a city's barangays hold none; a bare GROUP BY would drop them off
 *    the map, the table and the CSV, and the boss would read an empty area as
 *    "no data" instead of "zero".
 *
 * 4. A date range narrows the BASE query, never a column. With one, every
 *    number on the row (total, categories, new_*, prev_7d, the statuses) is
 *    "of the listings added on those Manila days that are still on the site",
 *    so the row keeps its one shape and every toggle keeps working. It is
 *    bound as two instants by this class's baseListingQuery() override (point
 *    2 again), deliberately NOT through the parent's date_start/date_end: that
 *    filter compares UTC day literals, so its "day" starts at 08:00 Manila.
 *
 * Province identity is {@see ProvinceCanonicalizer}'s, not `provinces.id`'s —
 * see that class for why the two disagree. City identity is
 * {@see CityGroup}'s, and barangay identity folds identical-name twin rows
 * inside one city on {@see BarangayNameMatcher::foldKey()} — the registry
 * carries 361 such duplicate groups and a polygon can only ever point at one.
 *
 * One more contract rides on those folds: {@see areaListingsQuery()} restates,
 * as a WHERE, exactly which listings each *Buckets() method folds onto one
 * area. The details card and the All Listings "View these listings" link both
 * stand on it, and promise the admin the number on the map. Change a fold and
 * that method changes with it; the area-membership tests compare the two for
 * every row at every tier.
 */
class ListingHeatmapService extends ListingInsightsService
{
    /** Seconds the counts payload stays fresh; echoed to the client as `ttl`. */
    public const TTL = 55;

    /**
     * Seconds an area's details (typical prices, agents) stay cached. They are
     * read once per click, never polled, and a median does not move in minutes.
     */
    public const TTL_AREA = 600;

    /**
     * TTL for a range that ended before today. Nothing new can be added to a
     * past day, so only an edit to an old listing (a delete, a status or
     * category change) moves it: there is no live poll to keep up with.
     */
    public const TTL_PAST = 600;

    /** The first day a range may start on; anything earlier is a typo, not a question. */
    public const EARLIEST_DAY = '2000-01-01';

    /** The tiers, in drill order. Anything else is coerced to the first. */
    public const LEVELS = ['province', 'city', 'barangay'];

    /** Response key => categories.name, in the order the frontend renders them. */
    private const CATEGORIES = [
        'for_sale' => 'For Sale',
        'for_rent' => 'For Rent',
        'foreclosure' => 'Foreclosed',
    ];

    /**
     * Every numeric field on a row, in response order. Also the zero template.
     * sold / rented / leased are the TRANSACTION_STATUSES values (the column
     * aliases aggregate() selects); the two lists change together.
     */
    private const METRICS = [
        'total', 'new_1d', 'new_7d', 'new_30d', 'prev_7d', 'sold', 'rented', 'leased',
        'for_sale', 'for_sale_new_1d', 'for_sale_new_7d', 'for_sale_new_30d',
        'for_sale_prev_7d', 'for_sale_sold', 'for_sale_rented', 'for_sale_leased',
        'for_rent', 'for_rent_new_1d', 'for_rent_new_7d', 'for_rent_new_30d',
        'for_rent_prev_7d', 'for_rent_sold', 'for_rent_rented', 'for_rent_leased',
        'foreclosure', 'foreclosure_new_1d', 'foreclosure_new_7d', 'foreclosure_new_30d',
        'foreclosure_prev_7d', 'foreclosure_sold', 'foreclosure_rented', 'foreclosure_leased',
    ];

    /** Per-instance memo of cityGroupIds(): the controller and heatmap() ask for the same city. */
    private array $cityGroupCache = [];

    /**
     * Per-instance memo: every `cities` id, nationwide, under its
     * {@see CityNameMatcher::normalize()}d name — the name half of the city
     * fold key. Null until cityIdsNamed() first needs it.
     *
     * @var array<string, int[]>|null
     */
    private ?array $cityIdsByName = null;

    /** Per-instance memo of barangayFoldIds(): the controller and areaListingsQuery() ask for the same barangay. */
    private array $barangayFoldCache = [];

    /**
     * The Manila calendar days this call counts listings ADDED on, as the
     * half-open [from, to) pair of Asia/Manila midnights — null = all time.
     * Set, and always reset, by heatmap() right after configure(); read by
     * baseListingQuery(), so every sub-query of one call sees one range.
     *
     * @var array{from: Carbon, to: Carbon}|null
     */
    private ?array $createdBetween = null;

    /**
     * Fold a `provinces.id` onto the id the heatmap actually paints.
     *
     * Public because the controller needs it BEFORE the service runs: the
     * canonical id is part of the counts cache key, so 82 and 83 must not end
     * up computing (and caching) the same answer twice.
     */
    public function canonicalProvinceId(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return ProvinceCanonicalizer::idMap($this->provinceNames())[$id] ?? $id;
    }

    /**
     * Fold a `cities.id` onto the id the heatmap paints its town under — the
     * lowest polygon-linked id of the city group, else the lowest id (exactly
     * the survivor the city tier's fold picks, see {@see CityGroup::canonicalId()}).
     *
     * Public for the same reason as canonicalProvinceId(): it is part of the
     * barangay tier's cache key, so Catbalogan 3 and Catbalogan 5 share one
     * cached answer instead of computing an identical one twice.
     */
    public function canonicalCityId(int $cityId): int
    {
        return CityGroup::canonicalId(
            $this->cityGroupIds($cityId),
            $this->linkedAreaIds('city', $this->boundariesVersion())
        );
    }

    /**
     * Every `cities` id that is the same town as $cityId, ascending.
     *
     * Only the rows of the city's province group are loaded (not all ~1,650
     * cities) because a fold key always contains the canonical province, so
     * nothing outside that group can share it. An id with no `cities` row is a
     * group of itself.
     *
     * @return int[]
     */
    public function cityGroupIds(int $cityId): array
    {
        if (isset($this->cityGroupCache[$cityId])) {
            return $this->cityGroupCache[$cityId];
        }

        $city = DB::table('cities')->where('id', $cityId)->first(['id', 'name', 'province_id']);
        if ($city === null) {
            return $this->cityGroupCache[$cityId] = [$cityId];
        }

        $provinceNames = $this->provinceNames();
        $candidates = DB::table('cities')->select('id', 'name', 'province_id');

        if ($city->province_id === null) {
            $candidates->whereNull('province_id');
        } else {
            $candidates->whereIn('province_id', ProvinceCanonicalizer::groupIds($provinceNames, (int) $city->province_id));
        }

        return $this->cityGroupCache[$cityId] = CityGroup::groupIds(
            $candidates->get(),
            ProvinceCanonicalizer::idMap($provinceNames),
            $cityId
        );
    }

    /**
     * Turn a requested range into the one the heatmap answers for.
     *
     * Both bounds are Asia/Manila calendar days (Y-m-d), inclusive. A day
     * after today is clamped to today rather than refused: nothing has been
     * added there yet, so "Oct 1 → Oct 31" asked on Oct 20 IS "Oct 1 → Oct 20".
     * The start is then held at or before the end, so a range wholly in the
     * future collapses onto today.
     *
     * Public because the controller needs it BEFORE the service runs: the
     * resolved range is part of the cache key, so a clamped request and its
     * unclamped equivalent must share one entry (as with canonicalProvinceId()).
     *
     * Null when neither bound is given. `clamped` = either bound moved; `past`
     * = the range ended before today (the controller caches it for TTL_PAST).
     *
     * @return array{date_start: string, date_end: string, clamped: bool, past: bool}|null
     */
    public function resolveRange(?string $dateStart, ?string $dateEnd): ?array
    {
        $dateStart = $dateStart !== null && $dateStart !== '' ? $dateStart : null;
        $dateEnd = $dateEnd !== null && $dateEnd !== '' ? $dateEnd : null;

        if ($dateStart === null && $dateEnd === null) {
            return null;
        }
        if ($dateStart === null || $dateEnd === null) {
            // The controller's required_with makes this unreachable over HTTP;
            // half a range from anywhere else is a bug, not "all time".
            throw new InvalidArgumentException('A date range needs both date_start and date_end.');
        }

        $todayManila = Carbon::now('Asia/Manila')->format('Y-m-d');

        // Zero-padded Y-m-d sorts as a string exactly as it does as a date.
        $end = min($dateEnd, $todayManila);
        $start = min($dateStart, $end);

        return [
            'date_start' => $start,
            'date_end' => $end,
            'clamped' => $end !== $dateEnd || $start !== $dateStart,
            'past' => $end < $todayManila,
        ];
    }

    /**
     * The full counts payload for one level and scope.
     *
     * @param  'province'|'city'|'barangay'  $level
     * @param  int|null  $provinceId  canonical or not — folded either way; ignored at barangay level
     * @param  int[]|null  $agentIds  null = admin (unscoped), list = team leader
     * @param  int|null  $cityId  any id of the city group; REQUIRED at barangay level, ignored elsewhere
     * @param  array{date_start: string, date_end: string, past?: bool}|null  $range  resolveRange()'s answer; null = all time
     */
    public function heatmap(string $level, ?int $provinceId = null, ?array $agentIds = null, ?int $cityId = null, ?array $range = null): array
    {
        $startedAt = microtime(true);
        $level = in_array($level, self::LEVELS, true) ? $level : 'province';

        if ($level === 'barangay' && $cityId === null) {
            throw new InvalidArgumentException('The barangay tier needs a city_id.');
        }

        $provinceNames = $this->provinceNames();
        $idMap = ProvinceCanonicalizer::idMap($provinceNames);

        // Scope is derived from the level, never from whatever params arrived:
        // the barangay tier is defined by its city, the other two by their
        // province (or nothing). A stray province_id at barangay level must
        // not fork the answer.
        if ($level === 'barangay') {
            $provinceId = null;
        } else {
            $cityId = null;
        }

        $canonicalProvinceId = $provinceId !== null ? ($idMap[$provinceId] ?? $provinceId) : null;

        // Scoping to "Samar" must also pick up the rows filed under its
        // duplicate row, or a third of the province's listings vanish.
        $groupIds = $provinceId !== null
            ? ProvinceCanonicalizer::groupIds($provinceNames, $provinceId)
            : [];

        $windows = $this->windows();
        $version = $this->boundariesVersion();

        $this->configure([], $agentIds);
        // Always (re)set: an instance reused for a second call must never
        // carry the first call's range into it.
        $this->createdBetween = $range !== null ? $this->rangeBounds($range) : null;
        if ($groupIds !== []) {
            // Not configure()'s province_id: that filters on ONE id, and the
            // whole point of the group is that several ids are the same place.
            $this->scopeProvinceIds = $groupIds;
        }

        $canonicalCityId = null;
        $city = null;

        if ($level === 'barangay') {
            $cityGroupIds = $this->cityGroupIds($cityId);
            $canonicalCityId = CityGroup::canonicalId($cityGroupIds, $this->linkedAreaIds('city', $version));
            $city = $this->cityContext($cityGroupIds, $canonicalCityId, $provinceNames, $idMap);
            $canonicalProvinceId = $city['province_id'];

            // The whole group, for the same reason as the province group above.
            $this->scopeCityIds = $cityGroupIds;

            $linked = $this->linkedAreaIds('barangay', $version, $cityGroupIds, $canonicalCityId);
            $buckets = $this->barangayBuckets($cityGroupIds, $city, $windows, $linked);
        } elseif ($level === 'city') {
            $linked = $this->linkedAreaIds('city', $version);
            $buckets = $this->cityBuckets($provinceNames, $idMap, $groupIds, $windows, $linked);
        } else {
            $linked = $this->linkedAreaIds('province', $version);
            $buckets = $this->provinceBuckets($provinceNames, $idMap, $canonicalProvinceId, $windows);
        }

        $data = [];
        foreach ($buckets as $bucket) {
            $data[] = $this->row($bucket, $level, $linked, $provinceNames);
        }

        // Biggest first — the map legend, the Top Areas panel and the CSV all
        // read top-down, and the "Unknown" row is never the headline.
        usort($data, function ($a, $b) {
            if ($a['id'] === null || $b['id'] === null) {
                return ($a['id'] === null ? 1 : 0) <=> ($b['id'] === null ? 1 : 0);
            }

            return [$b['total'], $a['name']] <=> [$a['total'], $b['name']];
        });

        return [
            'level' => $level,
            'province_id' => $canonicalProvinceId,
            'city_id' => $canonicalCityId,
            'generated_at' => Carbon::now('UTC')->toIso8601ZuluString(),
            'ttl' => $range !== null && ! empty($range['past']) ? self::TTL_PAST : self::TTL,
            'scope' => $agentIds === null ? 'admin' : 'team',
            'boundaries_version' => $version,
            'meta' => [
                'windows' => [
                    'today_from' => $windows['today']->toIso8601String(),
                    'd7_from' => $windows['d7']->toIso8601String(),
                    'd14_from' => $windows['d14']->toIso8601String(),
                    'd30_from' => $windows['d30']->toIso8601String(),
                ],
                'range' => $this->createdBetween === null ? null : [
                    'date_start' => (string) $range['date_start'],
                    'date_end' => (string) $range['date_end'],
                    'from' => $this->createdBetween['from']->toIso8601String(),
                    // Exclusive: the midnight AFTER the last day.
                    'to' => $this->createdBetween['to']->toIso8601String(),
                    'days' => (int) round($this->createdBetween['from']->diffInDays($this->createdBetween['to'])),
                ],
                'compute_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
            'data' => $data,
            'totals' => $this->totals($data),
        ];
    }

    /**
     * Listings query for ONE heatmap area: exactly the rows that area's count is built from.
     *
     * heatmap()'s own base query — the same exclusions, standard categories,
     * joins, range and agent scope — narrowed to the listings the *Buckets()
     * method of that tier folds onto the area:
     *
     *   province — the province's group ids (Samar 82 + 83) on the COALESCE
     *              province id, as provinceBuckets() folds them.
     *   city     — cityBuckets()' fold key: the canonical province AND the
     *              normalized town name, both read off the LISTING. See
     *              cityFoldScope() for why that is not just "the city group".
     *   barangay — the city group, exactly as the barangay tier scopes, and the
     *              address barangays sharing the area's fold key, twins and all.
     *
     * Every number the area shows — its total, a category's total, a status,
     * a window under a range — is then a count of this query, plus the same
     * single condition the aggregate puts in that column.
     *
     * No columns are selected: count() it, or select('listings.id') to use it
     * as a subquery. One row per listing, because every join is on a primary key.
     *
     * The heatmap's id-less "Unknown" rows (no place resolved) have no id to ask for.
     *
     * @param  'province'|'city'|'barangay'  $level
     * @param  int  $areaId  the row's id as heatmap() returns it, or any id that folds onto it (82 for Samar, Catbalogan 3 for 5, Lagtang 18 for 19)
     * @param  int|null  $cityId  any id of the city group; REQUIRED at barangay level, ignored elsewhere
     * @param  int[]|null  $agentIds  null = admin (unscoped), list = team leader
     * @param  array{date_start: string, date_end: string}|null  $range  resolveRange()'s answer; null = all time
     * @param  string|null  $category  for_sale | for_rent | foreclosure; null or 'all' = all three
     * @return Builder
     */
    public function areaListingsQuery(string $level, int $areaId, ?int $cityId = null, ?array $agentIds = null, ?array $range = null, ?string $category = null)
    {
        if (! in_array($level, self::LEVELS, true)) {
            // Not coerced, unlike heatmap(): answering for a different tier
            // than the one asked about would be a wrong number, not a default.
            throw new InvalidArgumentException("Unknown heatmap level '{$level}'.");
        }
        if ($level === 'barangay' && $cityId === null) {
            throw new InvalidArgumentException('A barangay area needs its city_id.');
        }
        $categoryName = $this->categoryName($category);

        $provinceIds = null;
        $nameCityIds = [];
        $cityGroupIds = [];
        $barangayIds = [];

        // Resolved before configure(): these read the registry, not listings.
        if ($level === 'province') {
            $provinceIds = ProvinceCanonicalizer::groupIds($this->provinceNames(), $areaId);
        } elseif ($level === 'city') {
            ['province_ids' => $provinceIds, 'city_ids' => $nameCityIds] = $this->cityFoldScope($areaId);
        } else {
            $cityGroupIds = $this->cityGroupIds($cityId);
            $barangayIds = $this->barangayFoldIds($cityGroupIds, $areaId);
        }

        // Exactly heatmap()'s state: the agent scope, and the range always (re)set.
        $this->configure([], $agentIds);
        $this->createdBetween = $range !== null ? $this->rangeBounds($range) : null;

        if ($level === 'barangay') {
            $this->scopeCityIds = $cityGroupIds;
        } elseif ($provinceIds !== null) {
            // An empty list matches nothing (the base query's contract).
            $this->scopeProvinceIds = $provinceIds;
        }

        $query = $this->baseListingQuery();

        if ($level === 'city') {
            $query->whereIn(DB::raw($this->cityIdExpr()), $nameCityIds ?: [0]);
            if ($provinceIds === null) {
                // A town filed under no province keys under "none".
                $query->whereRaw($this->provinceIdExpr().' IS NULL');
            }
        } elseif ($level === 'barangay') {
            $query->whereIn('properties.address_id', $barangayIds ?: [0]);
        }

        if ($categoryName !== null) {
            $query->where('categories.name', $categoryName);
        }

        return $query;
    }

    /**
     * The id heatmap() paints an area under, from any id that folds onto it —
     * so twins requested separately share one cache entry, as with
     * canonicalProvinceId() and canonicalCityId().
     *
     * @param  int|null  $cityId  barangay level only (required there)
     */
    public function canonicalAreaId(string $level, int $areaId, ?int $cityId = null): int
    {
        return match ($level) {
            'province' => (int) $this->canonicalProvinceId($areaId),
            'city' => $this->canonicalCityId($areaId),
            'barangay' => $this->canonicalBarangayId($areaId, $cityId ?? throw new InvalidArgumentException('A barangay area needs its city_id.')),
            default => throw new InvalidArgumentException("Unknown heatmap level '{$level}'."),
        };
    }

    /**
     * The details card's extra numbers for ONE area: how many listings (the
     * row's total, by construction), how many agents list there, and the
     * typical For Sale asking price and For Rent monthly rent.
     *
     * None of these can be added up when twin areas fold together (a median
     * of medians is not a median; agents repeat across towns), which is why
     * they are asked for per area and never ride the 60 s counts poll.
     *
     * "Typical" is the median of the believable prices ({@see ListingPriceRules},
     * the same rules as Price Insights), with `count` saying how many prices it
     * was taken over. Under a category, the area is that category's listings
     * only, so a price whose category is ruled out is null rather than a
     * number about listings the card is not showing.
     *
     * @param  string|null  $category  for_sale | for_rent | foreclosure; null or 'all' = all three
     * @return array{level: string, id: int, city_id: ?int, category: string, range: ?array{date_start: string, date_end: string}, listings: int, agents: int, sale: ?array{median: float, count: int}, rent: ?array{median: float, count: int}, generated_at: string}
     */
    public function areaDetails(string $level, int $areaId, ?int $cityId = null, ?array $agentIds = null, ?array $range = null, ?string $category = null): array
    {
        $category = $category !== null && $category !== '' ? $category : 'all';
        $query = $this->areaListingsQuery($level, $areaId, $cityId, $agentIds, $range, $category);

        $listings = (clone $query)->count();

        return [
            'level' => $level,
            'id' => $this->canonicalAreaId($level, $areaId, $cityId),
            'city_id' => $level === 'barangay' ? $this->canonicalCityId($cityId) : null,
            'category' => $category,
            'range' => $range === null ? null : [
                'date_start' => (string) $range['date_start'],
                'date_end' => (string) $range['date_end'],
            ],
            'listings' => $listings,
            'agents' => $listings > 0 ? (int) (clone $query)->distinct()->count('listings.agent_id') : 0,
            'sale' => $this->typicalPrice($query, 'for_sale', $category),
            'rent' => $this->typicalPrice($query, 'for_rent', $category),
            'generated_at' => Carbon::now('UTC')->toIso8601ZuluString(),
        ];
    }

    /**
     * Narrow any listings query (Eloquent or not) to the listings ADDED on a
     * resolved range's Manila days: the same half-open instants a ranged
     * heatmap counts, so a list filtered this way agrees with the map.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @param  array{date_start: string, date_end: string}  $range  resolveRange()'s answer
     * @return T
     */
    public function whereAddedDuring($query, array $range)
    {
        return $this->whereCreatedBetween($query, $this->rangeBounds($range));
    }

    /**
     * The median believable price of the area's listings in one category, and
     * how many prices it was taken over — null when there are none, or when
     * the card's category already rules this one out.
     *
     * @return array{median: float, count: int}|null
     */
    private function typicalPrice($areaQuery, string $key, string $category): ?array
    {
        if ($category !== 'all' && $category !== $key) {
            return null;
        }

        // Only the price column: a median needs nothing else.
        $prices = ListingPriceRules::apply((clone $areaQuery)->where('categories.name', self::CATEGORIES[$key]))
            ->pluck('listings.price')
            ->map(fn ($price) => (float) $price)
            ->all();

        $median = ListingPriceRules::median($prices);

        return $median === null ? null : ['median' => round($median, 2), 'count' => count($prices)];
    }

    /** categories.name for a heatmap category key; null for none / 'all' (the three together). */
    private function categoryName(?string $category): ?string
    {
        if ($category === null || $category === '' || $category === 'all') {
            return null;
        }
        if (! isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException("Unknown heatmap category '{$category}'.");
        }

        return self::CATEGORIES[$category];
    }

    /**
     * What cityBuckets() folds onto one city's row, as two id lists for the
     * listing's own COALESCE expressions: the province ids of the row's
     * canonical province (null = the listing resolves to NO province, for a
     * town filed under none), and every `cities` id NATIONWIDE that carries the
     * row's normalized name.
     *
     * Why not just cityGroupIds(): cityBuckets() keys an aggregate row on the
     * LISTING's province — provinceIdExpr(), where a project's own prov_id
     * comes first — not on the province of the town the listing resolved to.
     * The two agree for every listing in the local copy of the data (checked
     * 2026-11-10), but a project filed under Bohol with its town set to Cebu's
     * Carmen is counted on Bohol's Carmen row, and a group-of-ids filter would
     * put it under Cebu's. Matching on both halves of the key makes the count
     * exact either way. The name half reads cityIdExpr()'s row, which is the
     * row cityNameExpr() names: the foreign key on projects.city_id makes them
     * one row whenever a project names a town.
     *
     * An id with no `cities` row names no town: both lists are empty, so the
     * area is too (no listing can resolve to it, by the same foreign keys).
     *
     * @return array{province_ids: int[]|null, city_ids: int[]}
     */
    private function cityFoldScope(int $cityId): array
    {
        $city = DB::table('cities')->where('id', $cityId)->first(['id', 'name', 'province_id']);

        if ($city === null) {
            return ['province_ids' => [], 'city_ids' => []];
        }

        return [
            'province_ids' => $city->province_id !== null
                ? ProvinceCanonicalizer::groupIds($this->provinceNames(), (int) $city->province_id)
                : null,
            'city_ids' => $this->cityIdsNamed((string) $city->name),
        ];
    }

    /**
     * Every `cities` id, nationwide, whose name folds to the same town name.
     *
     * @return int[]
     */
    private function cityIdsNamed(string $name): array
    {
        if ($this->cityIdsByName === null) {
            $index = [];
            foreach (DB::table('cities')->orderBy('id')->get(['id', 'name']) as $row) {
                $index[CityNameMatcher::normalize((string) $row->name)][] = (int) $row->id;
            }
            $this->cityIdsByName = $index;
        }

        return $this->cityIdsByName[CityNameMatcher::normalize($name)] ?? [];
    }

    /**
     * The address barangay ids barangayBuckets() folds onto one row of a city
     * group: every barangay sharing $barangayId's fold key that a listing in
     * the group can sit on. Empty when $barangayId is not one of them (it
     * belongs to another town), which makes the area empty rather than quietly
     * answering for a same-name barangay that is not the one asked about.
     *
     * The candidates are the group's registry rows plus one more kind:
     * barangays OUTSIDE the group that a listing still resolves into it,
     * because its project sits in this town while its unit's address barangay
     * sits in another. barangayBuckets() folds those by name too, onto the
     * town's own row of that name or onto a row of their own. Only a project
     * can do that — without one, a listing's town IS its barangay's — so they
     * are found through the projects in the group, never by scanning listings.
     *
     * @param  int[]  $cityGroupIds
     * @return int[] ascending
     */
    private function barangayFoldIds(array $cityGroupIds, int $barangayId): array
    {
        $memo = implode(',', $cityGroupIds).'|'.$barangayId;
        if (isset($this->barangayFoldCache[$memo])) {
            return $this->barangayFoldCache[$memo];
        }

        /** @var array<int, string> $candidates */
        $candidates = [];
        foreach (DB::table('barangays')->whereIn('city_id', $cityGroupIds)->get(['id', 'name']) as $row) {
            $candidates[(int) $row->id] = trim((string) $row->name);
        }

        $foreign = DB::table('properties')
            ->join('projects', function ($join) {
                // The base query's own project join: a deleted project resolves nothing.
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->whereIn('projects.city_id', $cityGroupIds)
            ->whereNotNull('properties.address_id')
            ->where(fn ($q) => $q->whereNull('barangays.city_id')->orWhereNotIn('barangays.city_id', $cityGroupIds))
            ->distinct()
            ->get(['properties.address_id', 'barangays.name']);

        foreach ($foreign as $row) {
            $candidates[(int) $row->address_id] = trim((string) ($row->name ?? ''));
        }

        $ids = [];
        if (isset($candidates[$barangayId])) {
            $key = $this->barangayKey($candidates[$barangayId]);
            foreach ($candidates as $id => $name) {
                if ($this->barangayKey($name) === $key) {
                    $ids[] = $id;
                }
            }
            sort($ids);
        }

        return $this->barangayFoldCache[$memo] = $ids;
    }

    /**
     * The id a barangay row is painted under: the lowest polygon-linked id of
     * its fold group, else the lowest — offerAreaId()'s survivor, the same rule
     * as cities. Itself when it is not a barangay of the town.
     */
    private function canonicalBarangayId(int $barangayId, int $cityId): int
    {
        $cityGroupIds = $this->cityGroupIds($cityId);
        $foldIds = $this->barangayFoldIds($cityGroupIds, $barangayId);

        if ($foldIds === []) {
            return $barangayId;
        }

        $version = $this->boundariesVersion();
        $canonicalCityId = CityGroup::canonicalId($cityGroupIds, $this->linkedAreaIds('city', $version));

        return CityGroup::canonicalId($foldIds, $this->linkedAreaIds('barangay', $version, $cityGroupIds, $canonicalCityId));
    }

    /**
     * The shared base query, narrowed to the call's range when there is one —
     * once, here, so the range reaches every column of the aggregate at once
     * instead of being re-applied (and one day forgotten) column by column.
     *
     * Bound as two UTC instants, the treatment the rolling windows get (class
     * docblock, point 2). NOT the parent's date_start/date_end: those compare
     * `listings.created_at` to UTC day literals, eight hours off the Manila day.
     */
    protected function baseListingQuery()
    {
        $query = parent::baseListingQuery();

        if ($this->createdBetween !== null) {
            $this->whereCreatedBetween($query, $this->createdBetween);
        }

        return $query;
    }

    /**
     * The one place a range becomes SQL: two UTC instants, half-open. Shared by
     * the heatmap's base query and whereAddedDuring() (All Listings), so the
     * map and the list can never disagree about which day a listing is from.
     *
     * @param  array{from: Carbon, to: Carbon}  $bounds  rangeBounds()'s answer
     */
    private function whereCreatedBetween($query, array $bounds)
    {
        return $query->where('listings.created_at', '>=', $this->bind($bounds['from']))
            ->where('listings.created_at', '<', $this->bind($bounds['to']));
    }

    /**
     * A resolved range as the instants it covers: the first day's Manila
     * midnight up to — not including — the midnight after the last day.
     * Half-open like the rolling windows, so no 23:59:59 is needed and a
     * listing at a day's seam is never dropped or counted twice.
     *
     * @param  array{date_start?: string, date_end?: string}  $range
     * @return array{from: Carbon, to: Carbon}
     */
    private function rangeBounds(array $range): array
    {
        $from = $this->manilaMidnight((string) ($range['date_start'] ?? ''));
        $to = $this->manilaMidnight((string) ($range['date_end'] ?? ''))->addDay();

        if ($to <= $from) {
            throw new InvalidArgumentException('A date range cannot end before it starts.');
        }

        return ['from' => $from, 'to' => $to];
    }

    /** The Asia/Manila midnight that starts a strict Y-m-d calendar day. */
    private function manilaMidnight(string $day): Carbon
    {
        $midnight = Carbon::createFromFormat('!Y-m-d', $day, 'Asia/Manila');

        // createFromFormat() quietly rolls 2026-02-30 over to March 2.
        if (! $midnight instanceof Carbon || $midnight->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException("Not a Y-m-d calendar day: '{$day}'.");
        }

        return $midnight;
    }

    /**
     * The "new since" cut-offs as Asia/Manila start-of-day instants.
     *
     * today = this Manila calendar day; d7 = that day and the six before it
     * (so "last 7 days" includes today); d30 likewise. d14 starts the week
     * BEFORE d7's: prev_7d is [d14, d7), the seven days new_7d has just let go
     * of, so the two weeks never share a listing. Kept as Carbon objects so the
     * response can report them with their +08:00 offset while the SQL binds
     * their UTC form.
     *
     * @return array{today: Carbon, d7: Carbon, d14: Carbon, d30: Carbon}
     */
    private function windows(): array
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();

        return [
            'today' => $today,
            'd7' => $today->copy()->subDays(6),
            'd14' => $today->copy()->subDays(13),
            'd30' => $today->copy()->subDays(29),
        ];
    }

    /** A window cut-off in the UTC literal form `listings.created_at` is stored in. */
    private function bind(Carbon $moment): string
    {
        return $moment->copy()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * The single grouped aggregate. $extra adds the city level's province
     * columns; everything else is identical between levels.
     *
     * Every measure is one condition on one listing, counted across all three
     * categories (`new_7d`) and inside each (`for_sale_new_7d`). They are plain
     * counts, so they fold by addition: twin rows into one area, barangays
     * into their city — the *Buckets() methods rely on exactly that.
     *
     * @return Collection<int, \stdClass>
     */
    private function aggregate(string $idExpr, string $nameExpr, array $extra, array $windows)
    {
        $query = $this->baseListingQuery();

        $groups = [DB::raw($idExpr), DB::raw($nameExpr)];
        $query->selectRaw("{$idExpr} as gid")->selectRaw("{$nameExpr} as gname");

        foreach ($extra as $alias => $expr) {
            $query->selectRaw("{$expr} as {$alias}");
            $groups[] = DB::raw($expr);
        }

        $query->selectRaw('COUNT(listings.id) as total');

        // alias => [condition, its bindings]. The condition is fixed SQL; every
        // value in it is a placeholder.
        $measures = [
            'new_1d' => ['listings.created_at >= ?', [$this->bind($windows['today'])]],
            'new_7d' => ['listings.created_at >= ?', [$this->bind($windows['d7'])]],
            'new_30d' => ['listings.created_at >= ?', [$this->bind($windows['d30'])]],
            // The week before new_7d's, half-open so the two never overlap.
            'prev_7d' => [
                'listings.created_at >= ? AND listings.created_at < ?',
                [$this->bind($windows['d14']), $this->bind($windows['d7'])],
            ],
        ];

        // The By Status tab's transaction statuses. A property's CURRENT
        // status, not a dated event: under a range, `sold` means "of the
        // listings added in the range, how many are sold now".
        foreach (self::TRANSACTION_STATUSES as $status) {
            $measures[$status] = ['properties.status = ?', [$status]];
        }

        foreach ($measures as $alias => [$condition, $bindings]) {
            $query->selectRaw("SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) as {$alias}", $bindings);
        }

        foreach (self::CATEGORIES as $key => $categoryName) {
            $query->selectRaw(
                "SUM(CASE WHEN categories.name = ? THEN 1 ELSE 0 END) as {$key}",
                [$categoryName]
            );
            foreach ($measures as $alias => [$condition, $bindings]) {
                $query->selectRaw(
                    "SUM(CASE WHEN categories.name = ? AND {$condition} THEN 1 ELSE 0 END) as {$key}_{$alias}",
                    [$categoryName, ...$bindings]
                );
            }
        }

        return $query->groupBy($groups)->get();
    }

    /**
     * Province rows: seed every canonical province with zeros, then fold the
     * aggregate onto canonical ids (82 → 83, 65 → 53).
     */
    private function provinceBuckets(array $provinceNames, array $idMap, ?int $canonicalProvinceId, array $windows): array
    {
        $buckets = [];

        foreach (array_unique(array_values($idMap)) as $canonicalId) {
            if ($canonicalProvinceId !== null && $canonicalId !== $canonicalProvinceId) {
                continue;
            }
            $buckets[(string) $canonicalId] = $this->emptyBucket(
                $canonicalId,
                (string) ($provinceNames[$canonicalId] ?? 'Unknown'),
                $canonicalId
            );
        }

        $rows = $this->aggregate($this->provinceIdExpr(), $this->provinceNameExpr(), [], $windows);

        foreach ($rows as $row) {
            if ($row->gid === null) {
                $key = 'unknown';
                if (! isset($buckets[$key])) {
                    $buckets[$key] = $this->emptyBucket(null, 'Unknown', null);
                }
            } else {
                $canonicalId = $idMap[(int) $row->gid] ?? (int) $row->gid;
                $key = (string) $canonicalId;
                if (! isset($buckets[$key])) {
                    // A province id with listings but no provinces row — keep
                    // its own name rather than inventing one.
                    $buckets[$key] = $this->emptyBucket(
                        $canonicalId,
                        (string) ($provinceNames[$canonicalId] ?? $row->gname ?? 'Unknown'),
                        $canonicalId
                    );
                }
            }

            $this->addMetrics($buckets[$key], $row);
        }

        return array_values($buckets);
    }

    /**
     * City rows: seed every city in scope, then fold the aggregate onto
     * (canonical province, normalized city name).
     *
     * Why fold by NAME and not by id: the `cities` table carries duplicate rows
     * for the same town inside one province, and a polygon can only ever point
     * at one of them. Two half-filled rows for "Carmen" would paint one and
     * grey the other. The survivor is the id the boundary actually references,
     * so the row the map can shade is the row that holds the count.
     *
     * The key is read off the LISTING (its provinceIdExpr, its city's name);
     * cityFoldScope() restates it for areaListingsQuery() and must follow it.
     */
    private function cityBuckets(array $provinceNames, array $idMap, array $groupIds, array $windows, array $linkedCities): array
    {
        $cityQuery = DB::table('cities')->select('id', 'name', 'province_id');
        if ($groupIds !== []) {
            $cityQuery->whereIn('province_id', $groupIds);
        }

        $buckets = [];

        foreach ($cityQuery->get() as $city) {
            $provinceId = $city->province_id !== null ? (int) $city->province_id : null;
            $canonicalProvince = $provinceId !== null ? ($idMap[$provinceId] ?? $provinceId) : null;
            $key = CityGroup::key($canonicalProvince, (string) $city->name);

            if (! isset($buckets[$key])) {
                $buckets[$key] = $this->emptyBucket(null, (string) $city->name, $canonicalProvince);
            }
            $this->offerAreaId($buckets[$key], (int) $city->id, (string) $city->name, $linkedCities);
        }

        $rows = $this->aggregate(
            $this->cityIdExpr(),
            $this->cityNameExpr(),
            ['pid' => $this->provinceIdExpr(), 'pname' => $this->provinceNameExpr()],
            $windows
        );

        foreach ($rows as $row) {
            if ($row->gid === null) {
                $key = 'unknown';
                if (! isset($buckets[$key])) {
                    $buckets[$key] = $this->emptyBucket(null, 'Unknown', null);
                }
            } else {
                $provinceId = $row->pid !== null ? (int) $row->pid : null;
                $canonicalProvince = $provinceId !== null ? ($idMap[$provinceId] ?? $provinceId) : null;
                $key = CityGroup::key($canonicalProvince, (string) ($row->gname ?? ''));

                if (! isset($buckets[$key])) {
                    $buckets[$key] = $this->emptyBucket(null, (string) ($row->gname ?? 'Unknown'), $canonicalProvince);
                }
                $this->offerAreaId($buckets[$key], (int) $row->gid, (string) ($row->gname ?? ''), $linkedCities);
            }

            $this->addMetrics($buckets[$key], $row);
        }

        return array_values($buckets);
    }

    /**
     * Barangay rows of one city group: seed every registry row of the group,
     * then fold the aggregate (grouped on `properties.address_id`, the same
     * barangay definition as the rest of Listing Insights) onto the fold key.
     *
     * The fold key is {@see BarangayNameMatcher::foldKey()} — the whole name
     * squashed, so Talisay's two identical "Lagtang" rows become one while
     * Iloilo's "San Isidro (Jaro)"/"San Isidro (La Paz)" and Subic's "Asinan
     * Poblacion"/"Asinan Proper" each stay two rows. It is deliberately NOT
     * the matcher's fullKey(), which drops the Poblacion marker and "Proper"
     * to reconcile two SOURCES; here a spelling difference inside one source
     * is a real difference, and folding one away would leave its polygon
     * without a counts row for the map to shade or name. The group is fixed
     * for the whole call, so it is not part of the key. The surviving id is
     * the one a polygon points at (then the lowest), same rule as cities: the
     * row the map can shade is the row that holds the count.
     *
     * Every row carries the GROUP's canonical city and province, not the
     * particular twin row it came from — the admin clicked one town and every
     * row under it should say so.
     *
     * barangayFoldIds() restates this fold for areaListingsQuery() and must follow it.
     *
     * @param  int[]  $cityGroupIds
     * @param  array{id: int, name: string, province_id: ?int}  $city
     * @param  array<int, true>  $linkedBarangays
     */
    private function barangayBuckets(array $cityGroupIds, array $city, array $windows, array $linkedBarangays): array
    {
        $buckets = [];

        $registry = DB::table('barangays')
            ->select('id', 'name')
            ->whereIn('city_id', $cityGroupIds)
            ->orderBy('id')
            ->get();

        foreach ($registry as $barangay) {
            $name = trim((string) $barangay->name);
            $key = $this->barangayKey($name);

            if (! isset($buckets[$key])) {
                $buckets[$key] = $this->emptyBucket(null, $name, $city['province_id'], $city['id'], $city['name']);
            }
            $this->offerAreaId($buckets[$key], (int) $barangay->id, $name, $linkedBarangays);
        }

        $rows = $this->aggregate('properties.address_id', 'barangays.name', [], $windows);

        foreach ($rows as $row) {
            if ($row->gid === null) {
                $key = 'unknown';
                if (! isset($buckets[$key])) {
                    $buckets[$key] = $this->emptyBucket(null, 'Unknown', $city['province_id'], $city['id'], $city['name']);
                }
            } else {
                $name = trim((string) ($row->gname ?? ''));
                $key = $this->barangayKey($name);

                if (! isset($buckets[$key])) {
                    // A barangay that is in the group by its COALESCE city but
                    // not by its registry row (or a row with no name at all).
                    $buckets[$key] = $this->emptyBucket(null, $name, $city['province_id'], $city['id'], $city['name']);
                }
                $this->offerAreaId($buckets[$key], (int) $row->gid, $name, $linkedBarangays);
            }

            $this->addMetrics($buckets[$key], $row);
        }

        return array_values($buckets);
    }

    /** Fold key for a barangay inside one city group; a nameless row folds under its own marker. */
    private function barangayKey(string $name): string
    {
        $key = BarangayNameMatcher::foldKey($name);

        return $key !== '' ? $key : 'unnamed';
    }

    /**
     * The city a barangay payload is about: the canonical id, its name and
     * its canonical province, read from the `cities` row that id names.
     *
     * @param  int[]  $cityGroupIds
     * @return array{id: int, name: string, province_id: ?int}
     */
    private function cityContext(array $cityGroupIds, int $canonicalCityId, array $provinceNames, array $idMap): array
    {
        $rows = DB::table('cities')
            ->select('id', 'name', 'province_id')
            ->whereIn('id', $cityGroupIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $row = $rows[$canonicalCityId] ?? $rows->first();

        $provinceId = $row !== null && $row->province_id !== null ? (int) $row->province_id : null;

        return [
            'id' => $canonicalCityId,
            'name' => $row !== null ? (string) $row->name : '',
            'province_id' => $provinceId !== null ? ($idMap[$provinceId] ?? $provinceId) : null,
        ];
    }

    /**
     * Offer a candidate area id to a bucket. The polygon-linked id wins; a
     * tie between unlinked duplicates goes to the lowest id, so the surviving
     * id is stable across requests. Same rule for cities and barangays.
     */
    private function offerAreaId(array &$bucket, int $areaId, string $name, array $linked): void
    {
        $isLinked = isset($linked[$areaId]);
        $currentId = $bucket['id'];

        if ($currentId === null) {
            $bucket['id'] = $areaId;
            $bucket['name'] = $name !== '' ? $name : $bucket['name'];
            $bucket['linked'] = $isLinked;

            return;
        }

        if ($isLinked && ! $bucket['linked']) {
            $bucket['id'] = $areaId;
            $bucket['name'] = $name !== '' ? $name : $bucket['name'];
            $bucket['linked'] = true;

            return;
        }

        if ($isLinked === $bucket['linked'] && $areaId < $currentId) {
            $bucket['id'] = $areaId;
            $bucket['name'] = $name !== '' ? $name : $bucket['name'];
        }
    }

    private function emptyBucket(?int $id, string $name, ?int $provinceId, ?int $cityId = null, ?string $cityName = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'province_id' => $provinceId,
            'city_id' => $cityId,
            'city_name' => $cityName,
            'linked' => false,
            'metrics' => array_fill_keys(self::METRICS, 0),
        ];
    }

    private function addMetrics(array &$bucket, object $row): void
    {
        foreach (self::METRICS as $metric) {
            $bucket['metrics'][$metric] += (int) ($row->{$metric} ?? 0);
        }
    }

    /**
     * One bucket → one response row (contract section A).
     *
     * `city_id` / `city_name` are on every row so the shape is one shape: null
     * at province level, the row itself at city level, the group's canonical
     * city at barangay level.
     */
    private function row(array $bucket, string $level, array $linked, array $provinceNames): array
    {
        $metrics = $bucket['metrics'];
        $id = $bucket['id'];

        if ($level === 'province') {
            $provinceId = $id;
            $provinceName = $id !== null ? ($provinceNames[$id] ?? $bucket['name']) : null;
        } else {
            $provinceId = $bucket['province_id'];
            $provinceName = $provinceId !== null ? ($provinceNames[$provinceId] ?? null) : null;
        }

        $name = $bucket['name'] !== '' ? $bucket['name'] : ($level === 'barangay' && $id !== null ? 'Unnamed barangay' : 'Unknown');

        if ($level === 'city') {
            $cityId = $id;
            $cityName = $id !== null ? $name : null;
        } elseif ($level === 'barangay') {
            $cityId = $bucket['city_id'];
            $cityName = $bucket['city_name'];
        } else {
            $cityId = null;
            $cityName = null;
        }

        $byCategory = [];
        foreach (array_keys(self::CATEGORIES) as $key) {
            $byCategory[$key] = [
                'total' => $metrics[$key],
                'new_1d' => $metrics[$key.'_new_1d'],
                'new_7d' => $metrics[$key.'_new_7d'],
                'new_30d' => $metrics[$key.'_new_30d'],
                'prev_7d' => $metrics[$key.'_prev_7d'],
                'sold' => $metrics[$key.'_sold'],
                'rented' => $metrics[$key.'_rented'],
                'leased' => $metrics[$key.'_leased'],
            ];
        }

        return [
            'id' => $id,
            'name' => $name,
            'province_id' => $provinceId !== null ? (int) $provinceId : null,
            'province_name' => $provinceName !== null ? (string) $provinceName : null,
            'city_id' => $cityId !== null ? (int) $cityId : null,
            'city_name' => $cityName !== null ? (string) $cityName : null,
            'total' => $metrics['total'],
            'for_sale' => $metrics['for_sale'],
            'for_rent' => $metrics['for_rent'],
            'foreclosure' => $metrics['foreclosure'],
            'new_1d' => $metrics['new_1d'],
            'new_7d' => $metrics['new_7d'],
            'new_30d' => $metrics['new_30d'],
            'prev_7d' => $metrics['prev_7d'],
            'sold' => $metrics['sold'],
            'rented' => $metrics['rented'],
            'leased' => $metrics['leased'],
            'by_category' => $byCategory,
            'has_boundary' => $id !== null && isset($linked[$id]),
        ];
    }

    /**
     * KPI block. The identity the page asserts out loud is
     * mapped + unmapped + unknown = listings, so "unmapped" counts only rows
     * that HAVE an id but no polygon, and "unknown" only the id-less row.
     */
    private function totals(array $data): array
    {
        $listings = 0;
        $unknown = 0;
        $unmapped = 0;
        $max = ['total' => 0, 'new_1d' => 0, 'new_7d' => 0, 'new_30d' => 0];

        foreach ($data as $row) {
            $listings += $row['total'];

            if ($row['id'] === null) {
                $unknown += $row['total'];
            } elseif (! $row['has_boundary']) {
                $unmapped += $row['total'];
            }

            foreach ($max as $metric => $current) {
                if ($row[$metric] > $current) {
                    $max[$metric] = $row[$metric];
                }
            }
        }

        return [
            'areas' => count($data),
            'listings' => $listings,
            'unknown_listings' => $unknown,
            'unmapped_listings' => $unmapped,
            'max' => $max,
        ];
    }

    /** Current geometry generation; bumped by boundaries:import / :relink-cities / :relink-barangays. */
    public function boundariesVersion(): int
    {
        return (int) Cache::get('heatmap:boundaries:ver', 1);
    }

    /**
     * [area id => true] for every area that owns a polygon, cached for a day
     * under the geometry version so an import/relink invalidates it for free.
     *
     * Province and city lists are nationwide (~80 and ~1,500 ids). The
     * barangay list is PER CITY GROUP, keyed by the group's canonical id: a
     * nationwide list would be ~38k ints deserialised on every 60 s poll, for
     * a page that only ever looks at one city's ~80.
     *
     * @param  int[]|null  $cityGroupIds  barangay level only: the group to scope to
     * @param  int|null  $canonicalCityId  barangay level only: the group's cache id
     * @return array<int, true>
     */
    private function linkedAreaIds(string $level, int $version, ?array $cityGroupIds = null, ?int $canonicalCityId = null): array
    {
        if ($level === 'barangay') {
            $column = 'barangay_id';
            $key = "heatmap:linked:v{$version}:barangay:c{$canonicalCityId}";
        } else {
            $column = $level === 'city' ? 'city_id' : 'province_id';
            $key = "heatmap:linked:v{$version}:{$level}";
        }

        $ids = Cache::remember($key, 86400, function () use ($level, $column, $cityGroupIds) {
            $query = DB::table('boundaries')
                ->where('level', $level)
                ->whereNotNull($column);

            if ($level === 'barangay') {
                $query->whereIn('city_id', $cityGroupIds ?: [0]);
            }

            return $query->distinct()
                ->pluck($column)
                ->map(fn ($id) => (int) $id)
                ->all();
        });

        // Stored as a list (compact + driver-agnostic); flipped per call because
        // a hash lookup per area beats in_array() over ~1,500 ids.
        return array_fill_keys($ids, true);
    }
}
