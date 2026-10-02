<?php

namespace App\Services\Listing;

use App\Support\BarangayNameMatcher;
use App\Support\CityGroup;
use App\Support\ProvinceCanonicalizer;
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
 * Three things here are deliberate and easy to break:
 *
 * 1. ONE grouped query. The page shows 3 categories × 4 time windows and the
 *    user flips between them with zero network, so all 16 numbers per area are
 *    computed in a single pass of SUM(CASE WHEN …) columns rather than 16
 *    queries or 4 date-filtered calls.
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
 * Province identity is {@see ProvinceCanonicalizer}'s, not `provinces.id`'s —
 * see that class for why the two disagree. City identity is
 * {@see CityGroup}'s, and barangay identity folds identical-name twin rows
 * inside one city on {@see BarangayNameMatcher::foldKey()} — the registry
 * carries 361 such duplicate groups and a polygon can only ever point at one.
 */
class ListingHeatmapService extends ListingInsightsService
{
    /** Seconds the counts payload stays fresh; echoed to the client as `ttl`. */
    public const TTL = 55;

    /** The tiers, in drill order. Anything else is coerced to the first. */
    public const LEVELS = ['province', 'city', 'barangay'];

    /** Response key => categories.name, in the order the frontend renders them. */
    private const CATEGORIES = [
        'for_sale' => 'For Sale',
        'for_rent' => 'For Rent',
        'foreclosure' => 'Foreclosure',
    ];

    /** Every numeric field on a row, in response order. Also the zero template. */
    private const METRICS = [
        'total', 'new_1d', 'new_7d', 'new_30d',
        'for_sale', 'for_sale_new_1d', 'for_sale_new_7d', 'for_sale_new_30d',
        'for_rent', 'for_rent_new_1d', 'for_rent_new_7d', 'for_rent_new_30d',
        'foreclosure', 'foreclosure_new_1d', 'foreclosure_new_7d', 'foreclosure_new_30d',
    ];

    /** Per-instance memo of cityGroupIds(): the controller and heatmap() ask for the same city. */
    private array $cityGroupCache = [];

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
     * The full counts payload for one level and scope.
     *
     * @param  'province'|'city'|'barangay'  $level
     * @param  int|null  $provinceId  canonical or not — folded either way; ignored at barangay level
     * @param  int[]|null  $agentIds  null = admin (unscoped), list = team leader
     * @param  int|null  $cityId  any id of the city group; REQUIRED at barangay level, ignored elsewhere
     */
    public function heatmap(string $level, ?int $provinceId = null, ?array $agentIds = null, ?int $cityId = null): array
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
            'ttl' => self::TTL,
            'scope' => $agentIds === null ? 'admin' : 'team',
            'boundaries_version' => $version,
            'meta' => [
                'windows' => [
                    'today_from' => $windows['today']->toIso8601String(),
                    'd7_from' => $windows['d7']->toIso8601String(),
                    'd30_from' => $windows['d30']->toIso8601String(),
                ],
                'compute_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ],
            'data' => $data,
            'totals' => $this->totals($data),
        ];
    }

    /**
     * The three "new since" cut-offs as Asia/Manila start-of-day instants.
     *
     * today = this Manila calendar day; d7 = that day and the six before it
     * (so "last 7 days" includes today); d30 likewise. Kept as Carbon objects
     * so the response can report them with their +08:00 offset while the SQL
     * binds their UTC form.
     *
     * @return array{today: Carbon, d7: Carbon, d30: Carbon}
     */
    private function windows(): array
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();

        return [
            'today' => $today,
            'd7' => $today->copy()->subDays(6),
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

        $cutoffs = [
            'new_1d' => $this->bind($windows['today']),
            'new_7d' => $this->bind($windows['d7']),
            'new_30d' => $this->bind($windows['d30']),
        ];

        foreach ($cutoffs as $alias => $cutoff) {
            $query->selectRaw(
                "SUM(CASE WHEN listings.created_at >= ? THEN 1 ELSE 0 END) as {$alias}",
                [$cutoff]
            );
        }

        foreach (self::CATEGORIES as $key => $categoryName) {
            $query->selectRaw(
                "SUM(CASE WHEN categories.name = ? THEN 1 ELSE 0 END) as {$key}",
                [$categoryName]
            );
            foreach ($cutoffs as $alias => $cutoff) {
                $query->selectRaw(
                    "SUM(CASE WHEN categories.name = ? AND listings.created_at >= ? THEN 1 ELSE 0 END) as {$key}_{$alias}",
                    [$categoryName, $cutoff]
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
