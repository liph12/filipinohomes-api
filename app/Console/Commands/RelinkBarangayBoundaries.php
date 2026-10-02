<?php

namespace App\Console\Commands;

use App\Support\BarangayGroupPlacement;
use App\Support\BarangayNameMatcher;
use App\Support\CityGroup;
use App\Support\CityNameMatcher;
use App\Support\ProvinceCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Links every barangay polygon in `boundaries` to the `cities` row of its town
 * and the `barangays` row it is, so the listings heatmap can shade a city's
 * barangays. The barangay-level sibling of {@see RelinkCityBoundaries}.
 *
 * ── Why the town comes first ───────────────────────────────────────────────
 * The `barangays` registry is name-only — 42,334 rows of (name, city_id), no
 * codes, no coordinates — and "Poblacion" is the name of 527 of them. A
 * barangay name means nothing until you know the town, so the command first
 * places each TOWN (one `parent_psgc` group of polygons; the source's
 * ADM3_PCODE never merges two towns the way a repeated name would) and only
 * then compares barangay names inside that one town's registry rows.
 *
 * ── Two signals for the town, merged ───────────────────────────────────────
 *   Geometry vote: ST_Centroid of every polygon in the group, inside the linked
 *   city polygons (MBRContains prefilter, ST_Contains exact), set-based per
 *   chunk of groups, GROUP BY (group, city). Majority wins. See
 *   {@see centroidVotes()} for why a chunk first narrows the city polygons it
 *   may vote in — without that, the statement is a cross join and the
 *   whole-country run takes half an hour.
 *   Name path: the group's ADM3_EN, matched by {@see CityNameMatcher} inside
 *   the province its ADM2_EN canonicalises to — exact, then alias, then a
 *   fenced near-miss against towns no exact match claimed.
 *
 * Both are computed for every group and merged by {@see BarangayGroupPlacement}:
 * agree → geo+name; disagree → the in-province EXACT name wins (the city layer
 * is a coarser 2020 source with 148 unlinked polygons, so a sliver vote across
 * a border is likelier wrong than a scoped exact name) and the pair is printed;
 * vote only → geo-only; name only → name-only; neither → NULL, never rendered.
 *
 * ── Then the barangays, inside the town ────────────────────────────────────
 * {@see BarangayNameMatcher::matchCity()} runs its six tiers over ALL polygons
 * of the town's city group at once (Manila's 14 districts together), exact
 * tiers over every polygon before any guess, candidates ordered by listing
 * count so a same-name twin resolves to the row that carries inventory.
 *
 * Everything is written in ONE transaction after a full reset of the links on
 * this level, so the table always reflects exactly one run. `--dry-run`
 * computes and prints the identical report and writes nothing. `--report=`
 * also writes `<path>.links.json` ({ADM4_PCODE: barangay_id}) and
 * `<path>.groups.json` ({ADM3_PCODE: city_id}) so a run can be diffed against
 * the recorded prototype before it is trusted.
 */
class RelinkBarangayBoundaries extends Command
{
    protected $signature = 'boundaries:relink-barangays
        {--dry-run : Compute and print the report, write nothing}
        {--report= : Also write the report to this file path, plus <path>.links.json and <path>.groups.json}
        {--city= : Write and report only the towns placed in this cities.id (and its same-town twins); placement is still computed for every town}
        {--chunk=20 : Towns per centroid-vote pass; smaller means a tighter envelope and fewer city polygons to test against}';

    protected $description = 'Link barangay boundary polygons to cities and barangays rows, town-first';

    /** Listing categories the admin maps count. Mirrors ListingInsightsService. */
    private const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];

    /** Rows per UPDATE statement when writing links. */
    private const WRITE_CHUNK = 500;

    /**
     * Degrees the chunk's centroid envelope is grown by before it is used to
     * narrow the candidate city polygons. Only there to keep a one-polygon
     * chunk from degenerating into a zero-area rectangle; the envelope is a
     * prefilter, so growing it can never drop a true container.
     */
    private const ENVELOPE_PAD = 0.000001;

    /** @var string[] spatial calls that failed and were stepped over, for the report */
    private array $spatialErrors = [];

    /** How many centroid-vote passes ran (one per chunk, plus any per-town retry), for the report. */
    private int $voteStatements = 0;

    /** SRID of `boundaries.geom`, read once (see {@see geomSrid()}). */
    private ?int $geomSrid = null;

    public function handle(): int
    {
        // A one-off CLI pass can afford it: this command holds the whole
        // barangay registry, every polygon name and the per-polygon link maps
        // in memory at once (~90 MB on the real data), which a default 128M
        // CLI limit does not fit. Same reason ImportBoundaries raises it.
        ini_set('memory_limit', '2G');

        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $this->error('--chunk must be a positive number of towns per vote pass.');

            return self::FAILURE;
        }

        // ── Guards ─────────────────────────────────────────────────────────
        $barangayPolygons = DB::table('boundaries')->where('level', 'barangay')->count();
        if ($barangayPolygons === 0) {
            $this->error('No barangay polygons in `boundaries` — nothing to relink.');
            $this->warn('Run: php artisan boundaries:import storage/app/psa-namria-PHL-ADM4_2023_simplified.ndjson --level=barangay');

            return self::FAILURE;
        }

        $linkedCityPolygons = DB::table('boundaries')->where('level', 'city')->whereNotNull('city_id')->count();
        if ($linkedCityPolygons === 0) {
            $this->error('No LINKED city polygons in `boundaries` — the centroid vote has nothing to vote with.');
            $this->warn('Run: php artisan boundaries:relink-cities   (after boundaries:import --level=city)');

            return self::FAILURE;
        }

        $withoutParentCode = DB::table('boundaries')->where('level', 'barangay')->whereNull('parent_psgc')->count();
        if ($withoutParentCode > 0) {
            $this->error("{$withoutParentCode} barangay polygon(s) have no parent_psgc — towns are grouped by that code, so a row without one cannot be placed.");
            $this->warn('Re-import the barangay level from a source that carries ADM3_PCODE (the PSA/NAMRIA file does).');

            return self::FAILURE;
        }

        $withoutProvinceName = DB::table('boundaries')->where('level', 'barangay')->whereNull('grandparent_name')->count();
        if ($withoutProvinceName > 0) {
            $this->warn("{$withoutProvinceName} barangay polygon(s) have no grandparent_name (ADM2_EN); their name path is scoped by the vote's province instead, so a disagreement cannot be detected for them.");
        }

        $this->info("Relinking {$barangayPolygons} barangay polygon(s) against {$linkedCityPolygons} linked city polygon(s)…");

        // ── Reference data ─────────────────────────────────────────────────
        $provinceNames = [];
        foreach (DB::table('provinces')->orderBy('id')->get(['id', 'name']) as $p) {
            $provinceNames[(int) $p->id] = (string) $p->name;
        }
        $provinceIdMap = ProvinceCanonicalizer::idMap($provinceNames);

        // canonical province key => canonical province id (lowest on a tie).
        $keyToCanonical = [];
        foreach ($provinceNames as $id => $name) {
            $key = ProvinceCanonicalizer::key($name);
            $canonical = $provinceIdMap[$id] ?? $id;
            $keyToCanonical[$key] = isset($keyToCanonical[$key]) ? min($keyToCanonical[$key], $canonical) : $canonical;
        }

        $cityRows = DB::table('cities')->orderBy('id')->get(['id', 'name', 'province_id']);
        $cityNames = [];
        $cityProvince = [];
        $cityCanonicalProvince = [];
        $townKeyOf = [];          // city id => CityGroup key
        $townIds = [];            // CityGroup key => sorted city ids
        foreach ($cityRows as $c) {
            $id = (int) $c->id;
            $cityNames[$id] = (string) $c->name;
            $cityProvince[$id] = $c->province_id === null ? null : (int) $c->province_id;
            $canonical = $cityProvince[$id] === null ? null : ($provinceIdMap[$cityProvince[$id]] ?? $cityProvince[$id]);
            $cityCanonicalProvince[$id] = $canonical;
            $townKeyOf[$id] = CityGroup::key($canonical, $cityNames[$id]);
            $townIds[$townKeyOf[$id]][] = $id;
        }
        foreach ($townIds as &$ids) {
            sort($ids);
        }
        unset($ids);

        // --city: the requested row and its same-town twins. Checked here,
        // before the vote, so a typo fails in a millisecond and not a minute.
        $scopeCityIds = null;
        $cityOption = $this->option('city');
        if ($cityOption !== null && $cityOption !== '') {
            if (! isset($cityNames[(int) $cityOption])) {
                $this->error("--city={$cityOption} is not a cities.id.");

                return self::FAILURE;
            }
            $scopeCityIds = CityGroup::groupIds($cityRows, $provinceIdMap, (int) $cityOption);
        }

        $cityListingCounts = $this->listingCountsByCity();
        $barangayListingCounts = $this->listingCountsByBarangay();

        // Cities of each CANONICAL province, ordered by listing count desc then
        // id asc — CityNameMatcher takes the first exact hit, so the twin that
        // carries listings wins (see RelinkCityBoundaries).
        $candidatesByCanonical = [];
        foreach ($cityNames as $cityId => $name) {
            if ($cityCanonicalProvince[$cityId] === null) {
                continue;
            }
            $candidatesByCanonical[$cityCanonicalProvince[$cityId]][$cityId] = $name;
        }
        foreach ($candidatesByCanonical as &$rows) {
            uksort($rows, function ($a, $b) use ($cityListingCounts) {
                $byCount = ($cityListingCounts[(int) $b] ?? 0) <=> ($cityListingCounts[(int) $a] ?? 0);

                return $byCount !== 0 ? $byCount : ((int) $a <=> (int) $b);
            });
        }
        unset($rows);

        $barangayNames = [];
        $barangayCity = [];
        $barangaysByCity = [];
        foreach (DB::table('barangays')->orderBy('id')->get(['id', 'name', 'city_id']) as $b) {
            $id = (int) $b->id;
            $barangayNames[$id] = (string) $b->name;
            $barangayCity[$id] = $b->city_id === null ? null : (int) $b->city_id;
            if ($barangayCity[$id] !== null) {
                $barangaysByCity[$barangayCity[$id]][] = $id;
            }
        }

        // ── The towns: one group per parent_psgc ───────────────────────────
        /** @var array<string, array{name: string, province_name: ?string, rows: array<int, array{id: int, code: ?string, name: string}>}> $groups */
        $groups = [];
        $rows = DB::table('boundaries')
            ->where('level', 'barangay')
            ->orderBy('parent_psgc')
            ->orderBy('psgc_code')
            ->orderBy('id')
            ->get(['id', 'name', 'psgc_code', 'parent_psgc', 'parent_name', 'grandparent_name']);
        foreach ($rows as $r) {
            $pcode = (string) $r->parent_psgc;
            if (! isset($groups[$pcode])) {
                $groups[$pcode] = ['name' => '', 'province_name' => null, 'rows' => []];
            }
            if ($groups[$pcode]['name'] === '' && $r->parent_name !== null) {
                $groups[$pcode]['name'] = (string) $r->parent_name;
            }
            if ($groups[$pcode]['province_name'] === null && $r->grandparent_name !== null) {
                $groups[$pcode]['province_name'] = (string) $r->grandparent_name;
            }
            $groups[$pcode]['rows'][] = [
                'id' => (int) $r->id,
                'code' => $r->psgc_code === null ? null : (string) $r->psgc_code,
                'name' => (string) $r->name,
            ];
        }
        unset($rows);

        $this->line('  '.count($groups).' town(s) to place; voting in chunks of '.$chunk.'…');

        // ── Signal 1: the centroid vote ────────────────────────────────────
        // Chunked on parent_psgc in sort order, which is also roughly
        // geographic order (the code is region/province/city), so each chunk's
        // envelope stays small — that is what keeps the vote cheap.
        $votes = [];     // parent_psgc => majority row
        $chunks = array_chunk(array_keys($groups), $chunk);
        // The vote is the one step that takes minutes; without a bar the
        // operator sees a silent process and assumes it hung.
        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();
        foreach ($chunks as $pcodes) {
            foreach ($this->votesFor($pcodes) as $pcode => $voteRows) {
                $majority = BarangayGroupPlacement::majority($voteRows);
                if ($majority !== null) {
                    $votes[$pcode] = $majority;
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // ── Signal 2: the name path ────────────────────────────────────────
        // Exact (plain or alias) over EVERY town first, then near-miss against
        // towns no exact match claimed — the same order the city relink uses,
        // for the same reason: a guess never steals a town a name earned.
        $nameLink = [];   // parent_psgc => ['city_id' => int, 'how' => exact|alias|fuzzy]
        $claimed = [];
        $deferred = [];
        foreach ($groups as $pcode => $g) {
            $canonical = null;
            if ($g['province_name'] !== null) {
                $canonical = $keyToCanonical[ProvinceCanonicalizer::key($g['province_name'])] ?? null;
            } elseif (isset($votes[$pcode])) {
                $canonical = $cityCanonicalProvince[$votes[$pcode]['city_id']] ?? null;
            }
            $candidates = $canonical !== null ? ($candidatesByCanonical[$canonical] ?? []) : [];
            $townName = CityNameMatcher::rewriteSga($g['name']);

            $exact = $this->exactCityMatch($candidates, $townName);
            if ($exact !== null) {
                $nameLink[$pcode] = $exact;
                $claimed[$exact['city_id']] = true;

                continue;
            }

            $deferred[] = [$pcode, $townName, $candidates];
        }
        foreach ($deferred as [$pcode, $townName, $candidates]) {
            $cityId = CityNameMatcher::match($candidates, $townName, array_keys($claimed));
            if ($cityId !== null) {
                $nameLink[$pcode] = ['city_id' => $cityId, 'how' => 'fuzzy'];
                $claimed[$cityId] = true;
            }
        }

        // ── Merge: where is this town? ─────────────────────────────────────
        /** @var array<string, array{city_id: ?int, province_id: ?int, how: ?string}> $placement */
        $placement = [];
        $disagreements = [];
        foreach ($groups as $pcode => $g) {
            $voteCity = $votes[$pcode]['city_id'] ?? null;
            $nameCity = $nameLink[$pcode]['city_id'] ?? null;
            $nameExact = isset($nameLink[$pcode]) && $nameLink[$pcode]['how'] !== 'fuzzy';
            $sameTown = $voteCity !== null && $nameCity !== null
                && isset($townKeyOf[$voteCity], $townKeyOf[$nameCity])
                && $townKeyOf[$voteCity] === $townKeyOf[$nameCity];

            $decision = BarangayGroupPlacement::decide($voteCity, $nameCity, $nameExact, $sameTown);
            $cityId = $decision['city_id'];

            $placement[$pcode] = [
                'city_id' => $cityId,
                // The province of the city the town was placed in; the vote's
                // own province only when the cities row has none.
                'province_id' => $cityId === null ? null : ($cityProvince[$cityId] ?? $votes[$pcode]['province_id'] ?? null),
                'how' => $decision['how'],
            ];

            if ($voteCity !== null && $nameCity !== null && $voteCity !== $nameCity && ! $sameTown) {
                $disagreements[] = [
                    'pcode' => $pcode,
                    'name' => $g['name'],
                    'province_name' => $g['province_name'],
                    'polygons' => count($g['rows']),
                    'vote' => $votes[$pcode],
                    'name_link' => $nameLink[$pcode],
                    'winner' => $cityId,
                ];
            }
        }

        // ── Barangays, inside the town's city group ────────────────────────
        // All groups placed in one town are matched together, so Manila's 14
        // districts share one claim set, and the candidates are every registry
        // row of the town's twin city ids, ordered by listing count then id.
        $byTown = [];   // town key => ['city_id' => first placed id, 'polygons' => [boundary id => name]]
        foreach ($placement as $pcode => $p) {
            if ($p['city_id'] === null) {
                continue;
            }
            $key = $townKeyOf[$p['city_id']] ?? ('id|'.$p['city_id']);
            $byTown[$key]['city_id'] ??= $p['city_id'];
            foreach ($groups[$pcode]['rows'] as $row) {
                $byTown[$key]['polygons'][$row['id']] = $row['name'];
            }
        }

        $barangayLink = [];   // boundary id => ['id' => barangay id|null, 'how' => tier]
        foreach ($byTown as $key => $town) {
            $ids = [];
            foreach ($townIds[$key] ?? [$town['city_id']] as $cityId) {
                foreach ($barangaysByCity[$cityId] ?? [] as $barangayId) {
                    $ids[] = $barangayId;
                }
            }
            usort($ids, function ($a, $b) use ($barangayListingCounts) {
                $byCount = ($barangayListingCounts[$b] ?? 0) <=> ($barangayListingCounts[$a] ?? 0);

                return $byCount !== 0 ? $byCount : ($a <=> $b);
            });
            $candidates = [];
            foreach ($ids as $barangayId) {
                $candidates[$barangayId] = $barangayNames[$barangayId];
            }

            foreach (BarangayNameMatcher::matchCity($candidates, $town['polygons'], $cityNames[$town['city_id']] ?? null) as $boundaryId => $result) {
                $barangayLink[(int) $boundaryId] = $result;
            }
        }

        // ── Scope (--city) ─────────────────────────────────────────────────
        $selectedGroups = [];
        foreach ($placement as $pcode => $p) {
            if ($scopeCityIds === null || ($p['city_id'] !== null && in_array($p['city_id'], $scopeCityIds, true))) {
                $selectedGroups[$pcode] = true;
            }
        }
        if ($scopeCityIds !== null && $selectedGroups === []) {
            $this->warn('No town was placed in city '.implode('/', $scopeCityIds).' — nothing to write there; its rows are only wiped.');
        }

        // ── Report ─────────────────────────────────────────────────────────
        $report = $this->buildReport(
            dryRun: $dryRun,
            chunk: $chunk,
            groups: $groups,
            votes: $votes,
            nameLink: $nameLink,
            placement: $placement,
            disagreements: $disagreements,
            barangayLink: $barangayLink,
            selectedGroups: $selectedGroups,
            scopeCityIds: $scopeCityIds,
            cityNames: $cityNames,
            cityProvince: $cityProvince,
            provinceNames: $provinceNames,
            townKeyOf: $townKeyOf,
            townIds: $townIds,
            barangayNames: $barangayNames,
            barangayCity: $barangayCity,
            barangayListingCounts: $barangayListingCounts,
            cityListingCounts: $cityListingCounts,
        );

        $this->newLine();
        $this->line($report);

        $reportPath = $this->option('report');
        if ($reportPath) {
            @mkdir(dirname($reportPath), 0775, true);
            file_put_contents($reportPath, $report."\n");
            $this->writeSideFiles($reportPath, $groups, $placement, $barangayLink, $selectedGroups);
            $this->info("Report written to {$reportPath} (+ .links.json, .groups.json)");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written and heatmap:boundaries:ver was not bumped.');

            return self::SUCCESS;
        }

        // ── Write ──────────────────────────────────────────────────────────
        $this->writeLinks($groups, $placement, $barangayLink, $selectedGroups, $scopeCityIds);
        $this->bumpBoundariesVersion();

        $this->newLine();
        $this->info('Links written.');

        return self::SUCCESS;
    }

    /**
     * Exact city match for the name path: the plain spelling, then the alias,
     * in candidate order. Mirrors the two exact passes of
     * {@see CityNameMatcher::match()} without its near-miss pass, so the
     * command can run exact over every town before any guess is allowed.
     *
     * @param  array<int, string>  $candidates
     * @return array{city_id: int, how: string}|null
     */
    private function exactCityMatch(array $candidates, string $townName): ?array
    {
        $plain = CityNameMatcher::normalize($townName);
        if ($plain === '') {
            return null;
        }
        $alias = CityNameMatcher::aliasKey($townName);

        $targets = $alias === $plain ? [[$plain, 'exact']] : [[$plain, 'exact'], [$alias, 'alias']];
        foreach ($targets as [$target, $how]) {
            foreach ($candidates as $id => $name) {
                if (CityNameMatcher::normalize((string) $name) === $target) {
                    return ['city_id' => (int) $id, 'how' => $how];
                }
            }
        }

        return null;
    }

    /**
     * The centroid vote for one chunk of towns, as [parent_psgc => vote rows].
     *
     * A single chunk statement can fail on one invalid ring (ST_Centroid and
     * ST_Contains both throw on it), which would cost every town in the chunk
     * its vote for no reason. So a failed chunk is retried town by town, and
     * only the towns that still fail are stepped over and reported.
     *
     * @param  string[]  $parentPsgcs
     * @return array<string, array<int, object>>
     */
    private function votesFor(array $parentPsgcs): array
    {
        try {
            return $this->centroidVotes($parentPsgcs);
        } catch (\Throwable $e) {
            $this->spatialErrors[] = 'chunk of '.count($parentPsgcs).' town(s): '.$this->briefly($e->getMessage()).' — retried one town at a time';
        }

        $out = [];
        foreach ($parentPsgcs as $pcode) {
            try {
                $out += $this->centroidVotes([$pcode]);
            } catch (\Throwable $e) {
                $this->spatialErrors[] = "town {$pcode}: ".$this->briefly($e->getMessage()).' — no vote';
            }
        }

        return $out;
    }

    /**
     * The spatial query itself — the ONLY ST_* calls in this command, kept in
     * one overridable method so the decision rules can be tested on sqlite
     * where no ST_* function exists.
     *
     * ── Why this is three statements and not one ───────────────────────────
     * The obvious single statement joins the chunk's centroids straight onto
     * every linked city polygon. MySQL cannot use a SPATIAL index as a JOIN
     * index, so `EXPLAIN FORMAT=TREE` reports "Inner hash join (no condition)"
     * — a full cross product with MBRContains + ST_Contains evaluated on every
     * pair. Measured on the real data (MySQL 9.5, 42,048 barangay × 1,499 city
     * polygons): 30 towns / 734 polygons took 19.3 s, and the whole country
     * would be well over half an hour.
     *
     * So the chunk narrows the city side FIRST:
     *   1. one pass over the chunk's polygons for the bounding box of their
     *      centroids;
     *   2. the city polygons whose own MBR meets that box — a constant
     *      geometry, so this is a plain 1.5k-row filter, ~13 ms;
     *   3. the vote, against those candidates only (typically ~120 of 1,499).
     *
     * Step 2 can only ADD candidates, never drop a true one: a polygon that
     * contains one of the chunk's centroids necessarily has an MBR that meets
     * the box those centroids fit in. Verified row-for-row identical to the
     * single-statement form on a dense 25-town slice, and the whole-country
     * vote total is unchanged at every chunk size.
     *
     * `NO_MERGE(b)` materialises the centroids so ST_Centroid runs once per
     * polygon instead of once per (polygon, city) pair; servers that do not
     * understand optimizer hints read it as a comment.
     *
     * Measured whole-country cost with --chunk=20: 32 s in 83 chunks, slowest
     * chunk 7 s.
     *
     * Grouped by (town, city, province) so one row per city a town's centroids
     * fell in comes back and {@see BarangayGroupPlacement::majority()} picks
     * the winner.
     *
     * @param  string[]  $parentPsgcs
     * @return array<string, array<int, object>> parent_psgc => rows with city_id, province_id, votes
     */
    protected function centroidVotes(array $parentPsgcs): array
    {
        $this->voteStatements++;

        $placeholders = implode(', ', array_fill(0, count($parentPsgcs), '?'));

        $box = DB::selectOne(
            "SELECT MIN(ST_X(pt)) AS x1, MIN(ST_Y(pt)) AS y1, MAX(ST_X(pt)) AS x2, MAX(ST_Y(pt)) AS y2
             FROM (SELECT ST_Centroid(geom) AS pt
                     FROM boundaries
                    WHERE level = 'barangay' AND parent_psgc IN ({$placeholders})) t",
            $parentPsgcs
        );

        if ($box === null || $box->x1 === null) {
            return [];
        }

        $pad = self::ENVELOPE_PAD;
        $envelope = sprintf(
            'POLYGON((%1$.9F %2$.9F,%3$.9F %2$.9F,%3$.9F %4$.9F,%1$.9F %4$.9F,%1$.9F %2$.9F))',
            (float) $box->x1 - $pad,
            (float) $box->y1 - $pad,
            (float) $box->x2 + $pad,
            (float) $box->y2 + $pad
        );

        $candidateIds = [];
        foreach (DB::select(
            'SELECT id FROM boundaries
              WHERE level = \'city\'
                AND city_id IS NOT NULL
                AND MBRIntersects(geom, ST_GeomFromText(?, '.$this->geomSrid().'))',
            [$envelope]
        ) as $r) {
            $candidateIds[] = (int) $r->id;
        }

        if ($candidateIds === []) {
            return [];
        }

        $idPlaceholders = implode(', ', array_fill(0, count($candidateIds), '?'));
        $rows = DB::select(
            "SELECT /*+ NO_MERGE(b) */ b.parent_psgc, c.city_id, c.province_id, COUNT(*) AS votes
             FROM (SELECT parent_psgc, ST_Centroid(geom) AS pt
                     FROM boundaries
                    WHERE level = 'barangay' AND parent_psgc IN ({$placeholders})) b
             JOIN boundaries c
               ON c.id IN ({$idPlaceholders})
              AND MBRContains(c.geom, b.pt)
              AND ST_Contains(c.geom, b.pt)
             GROUP BY b.parent_psgc, c.city_id, c.province_id",
            array_merge($parentPsgcs, $candidateIds)
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->parent_psgc][] = $r;
        }

        return $out;
    }

    /**
     * The SRID `boundaries.geom` is stored in, read once per run.
     *
     * The envelope constant must carry the same SRID as the column or MySQL
     * refuses the comparison; the import writes SRID 0 (cartesian degrees),
     * but reading it keeps a re-imported table from breaking the vote.
     */
    private function geomSrid(): int
    {
        if ($this->geomSrid === null) {
            $row = DB::selectOne("SELECT ST_SRID(geom) AS srid FROM boundaries WHERE level = 'city' AND city_id IS NOT NULL LIMIT 1");
            $this->geomSrid = $row === null || $row->srid === null ? 0 : (int) $row->srid;
        }

        return $this->geomSrid;
    }

    /** First part of a SQLSTATE message — the rest is the whole query. */
    private function briefly(string $message): string
    {
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return mb_substr($message, 0, 160);
    }

    /**
     * [city id => listing count] under the same definition the admin insight
     * tiles use (project city first, else the property's barangay's city), so
     * the candidate ordering agrees with boundaries:relink-cities.
     *
     * @return array<int, int>
     */
    private function listingCountsByCity(): array
    {
        $rows = $this->countedListings()
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->selectRaw('COALESCE(projects.city_id, property_cities.id) AS city_id, COUNT(*) AS total')
            ->groupBy(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->get();

        $counts = [];
        foreach ($rows as $r) {
            if ($r->city_id !== null) {
                $counts[(int) $r->city_id] = (int) $r->total;
            }
        }

        return $counts;
    }

    /**
     * [barangay id => listing count] by `properties.address_id` — the admin
     * definition the heatmap's barangay tier aggregates by. Orders the
     * registry candidates so a same-name twin resolves to the row that holds
     * the inventory, and feeds the coverage figure.
     *
     * @return array<int, int>
     */
    private function listingCountsByBarangay(): array
    {
        $rows = $this->countedListings()
            ->whereNotNull('properties.address_id')
            ->selectRaw('properties.address_id AS barangay_id, COUNT(*) AS total')
            ->groupBy('properties.address_id')
            ->get();

        $counts = [];
        foreach ($rows as $r) {
            $counts[(int) $r->barangay_id] = (int) $r->total;
        }

        return $counts;
    }

    /**
     * The listings the admin maps count, joined down to their barangay. No
     * agent scope: this is a data-quality question about the whole catalogue.
     */
    private function countedListings(): Builder
    {
        return DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES);
    }

    /**
     * One transaction: wipe this level's links, then write the computed ones.
     *
     * The wipe is what makes the command idempotent and authoritative. Town
     * links go out grouped by (city, province, how) with `parent_psgc IN (…)`,
     * so 42k rows take a few hundred statements; barangay ids differ per row
     * and go out as CASE … WHEN batches of 500.
     *
     * With --city the wipe covers the rows currently in that city group AND
     * the rows of the towns now placed there, so a town that moved out is
     * cleared and a town that moved in is written; everything else is
     * untouched.
     *
     * @param  array<string, array{name: string, province_name: ?string, rows: array<int, array{id: int, code: ?string, name: string}>}>  $groups
     * @param  array<string, array{city_id: ?int, province_id: ?int, how: ?string}>  $placement
     * @param  array<int, array{id: ?int, how: string}>  $barangayLink
     * @param  array<string, true>  $selectedGroups
     * @param  int[]|null  $scopeCityIds
     */
    private function writeLinks(array $groups, array $placement, array $barangayLink, array $selectedGroups, ?array $scopeCityIds): void
    {
        $townUpdates = [];   // "city:province:how" => parent_psgc[]
        $barangayUpdates = [];   // boundary id => barangay id
        foreach ($selectedGroups as $pcode => $_) {
            $p = $placement[$pcode];
            if ($p['city_id'] === null) {
                continue;   // already NULL from the wipe
            }
            $townUpdates[$p['city_id'].':'.($p['province_id'] ?? 'null').':'.$p['how']][] = $pcode;
            foreach ($groups[$pcode]['rows'] as $row) {
                $barangayId = $barangayLink[$row['id']]['id'] ?? null;
                if ($barangayId !== null) {
                    $barangayUpdates[$row['id']] = $barangayId;
                }
            }
        }

        DB::transaction(function () use ($townUpdates, $barangayUpdates, $selectedGroups, $scopeCityIds) {
            $wipe = DB::table('boundaries')->where('level', 'barangay');
            if ($scopeCityIds !== null) {
                $selected = array_keys($selectedGroups);
                $wipe->where(function ($q) use ($scopeCityIds, $selected) {
                    $q->whereIn('city_id', $scopeCityIds);
                    if ($selected !== []) {
                        $q->orWhereIn('parent_psgc', $selected);
                    }
                });
            }
            $wipe->update(['city_id' => null, 'province_id' => null, 'barangay_id' => null, 'link_how' => null]);

            $now = Carbon::now();

            foreach ($townUpdates as $key => $pcodes) {
                [$cityId, $provinceId, $how] = explode(':', $key, 3);
                foreach (array_chunk($pcodes, self::WRITE_CHUNK) as $chunk) {
                    DB::table('boundaries')
                        ->where('level', 'barangay')
                        ->whereIn('parent_psgc', $chunk)
                        ->update([
                            'city_id' => (int) $cityId,
                            'province_id' => $provinceId === 'null' ? null : (int) $provinceId,
                            'link_how' => $how,
                            'updated_at' => $now,
                        ]);
                }
            }

            foreach (array_chunk($barangayUpdates, self::WRITE_CHUNK, true) as $chunk) {
                $cases = [];
                $bindings = [];
                foreach ($chunk as $boundaryId => $barangayId) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $boundaryId;
                    $bindings[] = $barangayId;
                }
                $ids = array_keys($chunk);
                DB::update(
                    'UPDATE boundaries SET barangay_id = CASE id '.implode(' ', $cases).' END, updated_at = ? WHERE id IN ('.implode(', ', array_fill(0, count($ids), '?')).')',
                    [...$bindings, $now->toDateTimeString(), ...$ids]
                );
            }
        });
    }

    /**
     * Invalidate the heatmap's 24 h geometry cache — see ImportBoundaries for
     * why this is a version pointer rather than a cache flush.
     */
    private function bumpBoundariesVersion(): void
    {
        try {
            $version = (int) Cache::get('heatmap:boundaries:ver', 1);
            Cache::forever('heatmap:boundaries:ver', $version + 1);
            $this->line('heatmap:boundaries:ver → '.($version + 1));
        } catch (\Throwable $e) {
            $this->warn('Could not bump heatmap:boundaries:ver: '.$e->getMessage()
                .' — clear the cache by hand so the map refetches geometry.');
        }
    }

    /**
     * The machine-readable twins of the report, for diffing a run against the
     * recorded prototype: {ADM4_PCODE: barangay_id|null} for every polygon in
     * scope and {ADM3_PCODE: city_id|null} for every town.
     *
     * @param  array<string, array{name: string, province_name: ?string, rows: array<int, array{id: int, code: ?string, name: string}>}>  $groups
     * @param  array<string, array{city_id: ?int, province_id: ?int, how: ?string}>  $placement
     * @param  array<int, array{id: ?int, how: string}>  $barangayLink
     * @param  array<string, true>  $selectedGroups
     */
    private function writeSideFiles(string $reportPath, array $groups, array $placement, array $barangayLink, array $selectedGroups): void
    {
        $links = [];
        foreach ($selectedGroups as $pcode => $_) {
            foreach ($groups[$pcode]['rows'] as $row) {
                $links[$row['code'] ?? ('boundary:'.$row['id'])] = $barangayLink[$row['id']]['id'] ?? null;
            }
        }
        $towns = [];
        foreach ($placement as $pcode => $p) {
            $towns[$pcode] = $p['city_id'];
        }

        file_put_contents($reportPath.'.links.json', json_encode($links, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($reportPath.'.groups.json', json_encode($towns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The whole point of the command, in text: what it did, what it could not
     * do, and whether the result is good enough to ship. Computed from the
     * in-memory assignment, so --dry-run prints exactly what a real run would.
     *
     * @param  array<string, array{name: string, province_name: ?string, rows: array<int, array{id: int, code: ?string, name: string}>}>  $groups
     * @param  array<string, array{city_id: int, province_id: ?int, votes: int, total: int}>  $votes
     * @param  array<string, array{city_id: int, how: string}>  $nameLink
     * @param  array<string, array{city_id: ?int, province_id: ?int, how: ?string}>  $placement
     * @param  array<int, array<string, mixed>>  $disagreements
     * @param  array<int, array{id: ?int, how: string}>  $barangayLink
     * @param  array<string, true>  $selectedGroups
     * @param  int[]|null  $scopeCityIds
     * @param  array<int, string>  $cityNames
     * @param  array<int, ?int>  $cityProvince
     * @param  array<int, string>  $provinceNames
     * @param  array<int, string>  $townKeyOf
     * @param  array<string, int[]>  $townIds
     * @param  array<int, string>  $barangayNames
     * @param  array<int, ?int>  $barangayCity
     * @param  array<int, int>  $barangayListingCounts
     * @param  array<int, int>  $cityListingCounts
     */
    private function buildReport(
        bool $dryRun,
        int $chunk,
        array $groups,
        array $votes,
        array $nameLink,
        array $placement,
        array $disagreements,
        array $barangayLink,
        array $selectedGroups,
        ?array $scopeCityIds,
        array $cityNames,
        array $cityProvince,
        array $provinceNames,
        array $townKeyOf,
        array $townIds,
        array $barangayNames,
        array $barangayCity,
        array $barangayListingCounts,
        array $cityListingCounts,
    ): string {
        $cityLabel = function (?int $cityId) use ($cityNames, $cityProvince, $provinceNames): string {
            if ($cityId === null) {
                return '—';
            }
            $provinceId = $cityProvince[$cityId] ?? null;

            return ($cityNames[$cityId] ?? '?').' / '.($provinceId !== null ? ($provinceNames[$provinceId] ?? '?') : '?')." (city {$cityId})";
        };

        $totalPolygons = array_sum(array_map(fn ($g) => count($g['rows']), $groups));

        $lines = [];
        $lines[] = '═══ boundaries:relink-barangays '.($dryRun ? '— DRY RUN ' : '').'— '.Carbon::now()->toDateTimeString().' ═══';
        if ($scopeCityIds !== null) {
            $lines[] = 'Scoped to city '.implode(' / ', array_map($cityLabel, $scopeCityIds)).': placement is computed for every town, but only the towns placed there are written and listed below.';
        }

        // ── Town placement ─────────────────────────────────────────────────
        $howGroups = array_fill_keys([...BarangayGroupPlacement::HOWS, 'unlinked'], 0);
        $howPolygons = $howGroups;
        foreach ($placement as $pcode => $p) {
            $how = $p['how'] ?? 'unlinked';
            $howGroups[$how]++;
            $howPolygons[$how] += count($groups[$pcode]['rows']);
        }
        $nameHows = ['exact' => 0, 'alias' => 0, 'fuzzy' => 0];
        foreach ($nameLink as $n) {
            $nameHows[$n['how']] = ($nameHows[$n['how']] ?? 0) + 1;
        }
        $unanimous = 0;
        $weakest = null;
        foreach ($votes as $pcode => $v) {
            if ($v['votes'] === $v['total']) {
                $unanimous++;
            }
            $share = $v['total'] > 0 ? $v['votes'] / $v['total'] : 0;
            if ($weakest === null || $share < $weakest['share']) {
                $weakest = ['share' => $share, 'pcode' => $pcode, 'v' => $v];
            }
        }

        $lines[] = '';
        $lines[] = 'Town placement ('.count($groups).' towns, '.$totalPolygons.' polygons; '.$this->voteStatements.' vote pass(es) in chunks of '.$chunk.')';
        $explain = [
            BarangayGroupPlacement::GEO_AND_NAME => 'vote and name agree',
            BarangayGroupPlacement::NAME_WINS => 'disagree; exact in-province name won',
            BarangayGroupPlacement::GEO_WINS => 'disagree; name was a near-miss, vote won',
            BarangayGroupPlacement::GEO_ONLY => 'no name match',
            BarangayGroupPlacement::NAME_ONLY => 'no centroid vote',
            'unlinked' => 'neither — never rendered',
        ];
        foreach ($explain as $how => $why) {
            $lines[] = sprintf('  %-10s %-42s %5d towns %6d polygons', $how, "($why)", $howGroups[$how], $howPolygons[$how]);
        }
        $lines[] = sprintf(
            '  Name path: exact %d / alias %d / near-miss %d / none %d.   Centroid vote: %d towns voted, %d unanimous%s.',
            $nameHows['exact'],
            $nameHows['alias'],
            $nameHows['fuzzy'],
            count($groups) - count($nameLink),
            count($votes),
            $unanimous,
            $weakest !== null ? sprintf(', weakest majority %d/%d (%s, %s)', $weakest['v']['votes'], $weakest['v']['total'], $groups[$weakest['pcode']]['name'], $weakest['pcode']) : ''
        );

        // ── Disagreements ──────────────────────────────────────────────────
        $lines[] = '';
        $lines[] = 'Disagreements between vote and name ('.count($disagreements).') — w/V = winner votes / votes cast, N = polygons';
        if ($disagreements === []) {
            $lines[] = '  none.';
        }
        foreach ($disagreements as $d) {
            $lines[] = sprintf(
                '  %-34s [%s] %s: vote → %s (%d/%d of N=%d) | name → %s (%s) | kept: %s',
                $d['name'],
                $d['province_name'] ?? '?',
                $d['pcode'],
                $cityLabel($d['vote']['city_id']),
                $d['vote']['votes'],
                $d['vote']['total'],
                $d['polygons'],
                $cityLabel($d['name_link']['city_id']),
                $d['name_link']['how'],
                $d['winner'] === $d['name_link']['city_id'] ? 'name' : 'vote'
            );
        }

        // ── Unplaced towns ─────────────────────────────────────────────────
        $unplaced = [];
        foreach ($placement as $pcode => $p) {
            if ($p['city_id'] === null) {
                $unplaced[] = sprintf('  %-40s %-28s %s (%d polygons)', $groups[$pcode]['name'], $groups[$pcode]['province_name'] ?? '?', $pcode, count($groups[$pcode]['rows']));
            }
        }
        sort($unplaced);
        $lines[] = '';
        $lines[] = 'Towns placed nowhere ('.count($unplaced).') — usually a town with no cities row; their polygons are never rendered';
        foreach (array_slice($unplaced, 0, 60) as $u) {
            $lines[] = $u;
        }
        if (count($unplaced) > 60) {
            $lines[] = '  … '.(count($unplaced) - 60).' more.';
        }

        // ── Barangay tiers (scoped) ────────────────────────────────────────
        $tierCounts = array_fill_keys([...BarangayNameMatcher::TIERS, BarangayNameMatcher::HOW_UNMATCHED], 0);
        $inUnplacedTown = 0;
        $scopedPolygons = 0;
        $unmatchedByCity = [];   // city id => names
        $linkedBarangayIds = [];   // barangay id => true
        $claimsByBarangay = [];   // barangay id => [polygon names] for non-fan-in tiers
        $fanInTargets = [];   // barangay id => polygon count
        $crossCity = [];
        foreach ($selectedGroups as $pcode => $_) {
            $p = $placement[$pcode];
            $scopedPolygons += count($groups[$pcode]['rows']);
            if ($p['city_id'] === null) {
                $inUnplacedTown += count($groups[$pcode]['rows']);

                continue;
            }
            $townKey = $townKeyOf[$p['city_id']] ?? null;
            foreach ($groups[$pcode]['rows'] as $row) {
                $link = $barangayLink[$row['id']] ?? ['id' => null, 'how' => BarangayNameMatcher::HOW_UNMATCHED];
                $tierCounts[$link['how']]++;
                if ($link['id'] === null) {
                    $unmatchedByCity[$p['city_id']][] = $row['name'];

                    continue;
                }
                $linkedBarangayIds[$link['id']] = true;
                if ($link['how'] === BarangayNameMatcher::HOW_FANIN) {
                    $fanInTargets[$link['id']] = ($fanInTargets[$link['id']] ?? 0) + 1;
                } else {
                    $claimsByBarangay[$link['id']][] = $row['name'];
                }
                $registryCity = $barangayCity[$link['id']] ?? null;
                $registryTown = $registryCity !== null ? ($townKeyOf[$registryCity] ?? null) : null;
                if ($registryTown !== $townKey) {
                    $crossCity[] = sprintf('  %s (%s) → %s (bgy %d, registry city %s)', $row['name'], $cityLabel($p['city_id']), $barangayNames[$link['id']] ?? '?', $link['id'], $cityLabel($registryCity));
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Barangay linking ('.$scopedPolygons.' polygons'.($scopeCityIds !== null ? ' in scope' : '').')';
        $tierWhy = [
            BarangayNameMatcher::HOW_EXACT_FULL => 'full key, parenthetical kept',
            BarangayNameMatcher::HOW_EXACT_OUTER => 'outer key',
            BarangayNameMatcher::HOW_ALT_NAME => 'alternate name in parentheses',
            BarangayNameMatcher::HOW_FUZZY => 'near-miss, unclaimed rows only',
            BarangayNameMatcher::HOW_FANIN => 'numbered siblings / (Pob.) → one row',
            BarangayNameMatcher::HOW_PREFIX => 'hyphen-prefix rename',
            BarangayNameMatcher::HOW_UNMATCHED => 'registry gap or non-barangay land unit',
        ];
        $n = 1;
        foreach (BarangayNameMatcher::TIERS as $tier) {
            $lines[] = sprintf('  %d. %-12s %-40s %6d', $n++, $tier, '('.$tierWhy[$tier].')', $tierCounts[$tier]);
        }
        $lines[] = sprintf('     %-12s %-40s %6d', 'unmatched', '('.$tierWhy[BarangayNameMatcher::HOW_UNMATCHED].')', $tierCounts[BarangayNameMatcher::HOW_UNMATCHED]);
        $lines[] = sprintf('     %-12s %-40s %6d', 'unplaced', '(in a town placed nowhere)', $inUnplacedTown);

        // ── Unmatched polygons by city ─────────────────────────────────────
        uasort($unmatchedByCity, fn ($a, $b) => count($b) <=> count($a));
        $lines[] = '';
        $lines[] = 'Unmatched polygons by city ('.array_sum(array_map('count', $unmatchedByCity)).' polygons in '.count($unmatchedByCity).' cities) — these shade nothing';
        foreach (array_slice($unmatchedByCity, 0, 40, true) as $cityId => $names) {
            sort($names);
            $lines[] = sprintf(
                '  %3d  %-44s %s%s',
                count($names),
                $cityLabel($cityId),
                implode(', ', array_slice($names, 0, 8)),
                count($names) > 8 ? ', …' : ''
            );
        }
        if (count($unmatchedByCity) > 40) {
            $lines[] = '  … '.(count($unmatchedByCity) - 40).' more cities.';
        }

        // ── Listing-bearing registry barangays with no polygon ─────────────
        // Scoped to the barangays of the cities in scope. Same-name twins in
        // one town fold onto the linked row in the heatmap, so a twin whose
        // sibling is linked is reported as folded, not as missing.
        $placedTownKeys = [];   // town keys that own at least one placed group
        foreach ($placement as $p) {
            if ($p['city_id'] !== null && isset($townKeyOf[$p['city_id']])) {
                $placedTownKeys[$townKeyOf[$p['city_id']]] = true;
            }
        }
        $twinKeyOf = [];   // barangay id => town key | full key
        $linkedTwinKeys = [];
        foreach ($barangayNames as $barangayId => $name) {
            $cityId = $barangayCity[$barangayId];
            if ($cityId === null || ! isset($townKeyOf[$cityId])) {
                continue;
            }
            // foldKey, not fullKey: the heatmap folds registry rows on
            // exactly that key, so "folded" here must mean what it means there.
            $twinKeyOf[$barangayId] = $townKeyOf[$cityId].'|'.BarangayNameMatcher::foldKey($name);
            if (isset($linkedBarangayIds[$barangayId])) {
                $linkedTwinKeys[$twinKeyOf[$barangayId]] = true;
            }
        }

        $inScope = fn (int $barangayId): bool => $scopeCityIds === null
            || ($barangayCity[$barangayId] !== null && in_array($barangayCity[$barangayId], $scopeCityIds, true));

        $totalListings = 0;
        $direct = 0;
        $folded = 0;
        $missing = [];
        foreach ($barangayListingCounts as $barangayId => $count) {
            if (! isset($barangayNames[$barangayId]) || $barangayCity[$barangayId] === null || ! $inScope($barangayId)) {
                continue;   // listings on no registry barangay are counted nowhere
            }
            $totalListings += $count;
            if (isset($linkedBarangayIds[$barangayId])) {
                $direct += $count;

                continue;
            }
            if (isset($twinKeyOf[$barangayId], $linkedTwinKeys[$twinKeyOf[$barangayId]])) {
                $folded += $count;
                $missing[] = ['count' => $count, 'folded' => true, 'id' => $barangayId];

                continue;
            }
            $missing[] = ['count' => $count, 'folded' => false, 'id' => $barangayId];
        }
        usort($missing, fn ($a, $b) => $b['count'] <=> $a['count']);

        $lines[] = '';
        $notFolded = array_filter($missing, fn ($m) => ! $m['folded']);
        $lines[] = 'Listing-bearing registry barangays with NO polygon ('.count($notFolded).'; '.array_sum(array_column($notFolded, 'count')).' listings; "twin" = folded onto a linked same-name row by the heatmap)';
        if ($missing === []) {
            $lines[] = '  none — every barangay that carries a listing has a shape.';
        }
        foreach (array_slice($missing, 0, 60) as $m) {
            $cityId = $barangayCity[$m['id']];
            $lines[] = sprintf(
                '  %6d  %-34s %-44s (bgy %d%s%s)',
                $m['count'],
                $barangayNames[$m['id']] === '' ? '(empty name)' : $barangayNames[$m['id']],
                $cityLabel($cityId),
                $m['id'],
                $m['folded'] ? ', twin' : '',
                isset($placedTownKeys[$townKeyOf[$cityId] ?? '']) ? '' : ', CITY UNPLACED'
            );
        }
        if (count($missing) > 60) {
            $lines[] = '  … '.(count($missing) - 60).' more, all smaller.';
        }

        // ── Coverage ───────────────────────────────────────────────────────
        $pct = fn (int $n) => $totalListings > 0 ? number_format(100 * $n / $totalListings, 2) : '0.00';
        $lines[] = '';
        $lines[] = 'Coverage (listings whose barangay has a polygon'.($scopeCityIds !== null ? ', in scope' : '').')';
        $lines[] = sprintf('  direct:                 %6d of %d  =  %s%%', $direct, $totalListings, $pct($direct));
        $lines[] = sprintf('  + same-name twin fold:  %6d          →  %s%%', $folded, $pct($direct + $folded));
        $lines[] = '  Target >= 98%. Listings that resolve to no registry barangay are counted nowhere and';
        $lines[] = '  are excluded from both sides of this ratio.';

        // ── Integrity checks ───────────────────────────────────────────────
        $overLinked = array_filter($claimsByBarangay, fn ($names) => count($names) > 1);
        $lines[] = '';
        $lines[] = 'Integrity checks';
        $lines[] = '  over-link (a registry row claimed by more than one polygon outside fan-in): '.count($overLinked).(count($overLinked) === 0 ? '  ✓' : '  ✗ MUST BE 0');
        foreach (array_slice($overLinked, 0, 20, true) as $barangayId => $names) {
            $lines[] = sprintf('    bgy %d %s ← %s', $barangayId, $barangayNames[$barangayId] ?? '?', implode(', ', $names));
        }
        $lines[] = '  cross-city (registry city is not the polygon\'s town): '.count($crossCity).(count($crossCity) === 0 ? '  ✓' : '  ✗ MUST BE 0');
        foreach (array_slice($crossCity, 0, 20) as $c) {
            $lines[] = '  '.$c;
        }
        $lines[] = '  fan-in shares (allowed many-to-one): '.array_sum($fanInTargets).' polygons → '.count($fanInTargets).' registry rows';

        // ── Spatial errors ─────────────────────────────────────────────────
        if ($this->spatialErrors !== []) {
            $lines[] = '';
            $lines[] = 'Spatial calls that failed and were stepped over ('.count($this->spatialErrors).')';
            $lines[] = '  A chunk that failed was retried one town at a time; a town listed here cast no vote and';
            $lines[] = '  was placed by name alone, or nowhere.';
            foreach (array_slice($this->spatialErrors, 0, 20) as $e) {
                $lines[] = '  '.$e;
            }
            if (count($this->spatialErrors) > 20) {
                $lines[] = '  … '.(count($this->spatialErrors) - 20).' more.';
            }
        }

        // ── The fixed Cebu City line ───────────────────────────────────────
        // Always printed, scope or not: the one town whose numbers are known
        // by heart (80 polygons, 81 registry rows, Banawa the only gap), so a
        // regression anywhere in the cascade shows up in one glance.
        $lines[] = '';
        $lines[] = $this->cebuCityLine($cityNames, $cityProvince, $provinceNames, $cityListingCounts, $townKeyOf, $townIds, $placement, $groups, $barangayLink, $barangayNames, $barangayCity, $barangayListingCounts);

        return implode("\n", $lines);
    }

    /**
     * "Cebu City (city 448): 80 polygons (80 linked), 80 linked of 81 registry rows; …".
     *
     * Found by name and province rather than by a hard-coded id, so the line
     * stays meaningful on a database whose ids differ.
     *
     * @param  array<int, string>  $cityNames
     * @param  array<int, ?int>  $cityProvince
     * @param  array<int, string>  $provinceNames
     * @param  array<int, int>  $cityListingCounts
     * @param  array<int, string>  $townKeyOf
     * @param  array<string, int[]>  $townIds
     * @param  array<string, array{city_id: ?int, province_id: ?int, how: ?string}>  $placement
     * @param  array<string, array{name: string, province_name: ?string, rows: array<int, array{id: int, code: ?string, name: string}>}>  $groups
     * @param  array<int, array{id: ?int, how: string}>  $barangayLink
     * @param  array<int, string>  $barangayNames
     * @param  array<int, ?int>  $barangayCity
     * @param  array<int, int>  $barangayListingCounts
     */
    private function cebuCityLine(
        array $cityNames,
        array $cityProvince,
        array $provinceNames,
        array $cityListingCounts,
        array $townKeyOf,
        array $townIds,
        array $placement,
        array $groups,
        array $barangayLink,
        array $barangayNames,
        array $barangayCity,
        array $barangayListingCounts,
    ): string {
        $cebu = null;
        foreach ($cityNames as $cityId => $name) {
            $provinceId = $cityProvince[$cityId];
            if (
                CityNameMatcher::normalize($name) === 'cebu'
                && $provinceId !== null
                && ProvinceCanonicalizer::key($provinceNames[$provinceId] ?? '') === 'cebu'
                && ($cebu === null || ($cityListingCounts[$cityId] ?? 0) > ($cityListingCounts[$cebu] ?? 0))
            ) {
                $cebu = $cityId;
            }
        }
        if ($cebu === null) {
            return 'Cebu City: no cities row named Cebu City in Cebu province — the fixed check line cannot be printed.';
        }

        $groupIds = $townIds[$townKeyOf[$cebu]] ?? [$cebu];
        $polygons = 0;
        $linked = 0;
        $linkedIds = [];
        foreach ($placement as $pcode => $p) {
            if ($p['city_id'] === null || ! in_array($p['city_id'], $groupIds, true)) {
                continue;
            }
            foreach ($groups[$pcode]['rows'] as $row) {
                $polygons++;
                $barangayId = $barangayLink[$row['id']]['id'] ?? null;
                if ($barangayId !== null) {
                    $linked++;
                    $linkedIds[$barangayId] = true;
                }
            }
        }

        $registry = [];
        $gaps = [];
        foreach ($barangayNames as $barangayId => $name) {
            if (! in_array($barangayCity[$barangayId], $groupIds, true)) {
                continue;
            }
            $registry[] = $barangayId;
            if (! isset($linkedIds[$barangayId])) {
                $gaps[] = $name.' ('.($barangayListingCounts[$barangayId] ?? 0).' listings)';
            }
        }

        return sprintf(
            'Cebu City (city %d): %d polygons (%d linked), %d linked of %d registry rows; registry rows without a polygon: %s',
            $cebu,
            $polygons,
            $linked,
            count($linkedIds),
            count($registry),
            $gaps === [] ? 'none' : implode(', ', $gaps)
        );
    }
}
