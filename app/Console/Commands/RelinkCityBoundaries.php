<?php

namespace App\Console\Commands;

use App\Support\CityNameMatcher;
use App\Support\ProvinceCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Re-links every city/municipality polygon in `boundaries` to the `cities` row
 * it actually is, geometry first and names only as a last resort.
 *
 * ── Why this command exists ────────────────────────────────────────────────
 * `boundaries:import` used to link by normalised name, first id wins. Place
 * names repeat all over the country, so that produced a map that looked fine
 * and was wrong: one `cities.id` owned up to ten polygons in ten different
 * provinces (San Jose / Batangas 10, San Isidro / Abra 9, San Miguel / Bohol 8),
 * 216 polygons were linked to nothing, and 22% of all listings sat in a city
 * with no shape at all — Gen. Santos (1,111 listings), Talisay City Cebu (961),
 * Bacolod (311), Naga City Cebu (194, stolen by Naga in Camarines Sur).
 *
 * The missing piece was never a cleverer string comparison. It was the
 * province: once you know which province a polygon is in, "Talisay" stops being
 * ambiguous. So this command resolves the province geometrically first, and only
 * then compares names, inside that one province.
 *
 * ── The cascade ────────────────────────────────────────────────────────────
 * Province, per polygon:
 *   1. centroid inside a province polygon (one set-based query, catches ~98%)
 *   2. largest area of intersection with a province polygon (coastal shapes
 *      whose centroid falls in the sea, island towns)
 *   3. nearest province by ST_Distance, within a ceiling (offshore islands)
 * City, per polygon, within the province group only:
 *   4. {@see CityNameMatcher} — exact, then nationwide-unique exact, then a
 *      tightly-fenced Levenshtein near-miss.
 *
 * Exact matches are allowed to share a city: the boundary file splits Manila
 * into 16 districts and this database has one "Manila City" row, so all 16
 * polygons legitimately point at it. Near-miss matches are not — a guess must
 * never take a city an exact match already earned, which is why the name stage
 * runs exact-first over every polygon before any fuzzy matching starts.
 *
 * Everything is written in ONE transaction, after a full reset of `city_id` and
 * `province_id` on this level, so the table always reflects exactly one run and
 * a re-import cannot leave stale links behind. `--dry-run` computes and prints
 * the identical report and writes nothing.
 */
class RelinkCityBoundaries extends Command
{
    protected $signature = 'boundaries:relink-cities
        {--dry-run : Compute and print the report, write nothing}
        {--report= : Also write the report to this file path}
        {--force : Run even though some provinces own no polygon (their towns will be mis-placed)}';

    protected $description = 'Re-link city/municipality boundary polygons to cities rows, province-first';

    /** Listing categories the admin maps count. Mirrors ListingInsightsService. */
    private const STANDARD_CATEGORIES = ['For Sale', 'For Rent', 'Foreclosure'];

    /**
     * How far (in SRID-0 degrees, ~111 km each) pass 3 may reach for a province.
     *
     * Pass 3 used to answer "which province?" for ANY polygon, however far away,
     * because ORDER BY ... LIMIT 1 always returns something. That turns a
     * missing province into a confident wrong answer. A polygon further than
     * this from every province polygon is not an offshore island of one — it is
     * a polygon whose province is not in the table, and leaving it unplaced is
     * the honest outcome.
     *
     * Sized for the real worst case: the far-flung island municipalities are
     * well inside one degree of their province's mainland, and on the current
     * data pass 3 never fires at all (passes 1-2 place all 1,647).
     */
    private const NEAREST_PROVINCE_MAX_DEGREES = 1.5;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $provinceBoundaries = DB::table('boundaries')->where('level', 'province')->count();
        if ($provinceBoundaries === 0) {
            $this->error('No province polygons in `boundaries` — passes 1-3 have nothing to place against.');
            $this->warn('Run: php artisan boundaries:import database/data/geo/geoBoundaries-PHL-ADM2_simplified.geojson --level=province');

            return self::FAILURE;
        }

        $cityBoundaries = DB::table('boundaries')->where('level', 'city')->count();
        if ($cityBoundaries === 0) {
            $this->error('No city polygons in `boundaries` — nothing to relink.');

            return self::FAILURE;
        }

        $this->info("Relinking {$cityBoundaries} city polygon(s) against {$provinceBoundaries} province polygon(s)…");

        // ── Reference data ─────────────────────────────────────────────────
        $provinceNames = [];
        foreach (DB::table('provinces')->orderBy('id')->get(['id', 'name']) as $p) {
            $provinceNames[(int) $p->id] = (string) $p->name;
        }
        $provinceIdMap = ProvinceCanonicalizer::idMap($provinceNames);

        if (! $this->provinceLayerIsComplete($provinceNames, $provinceIdMap)) {
            return self::FAILURE;
        }

        $cityNames = [];
        $cityProvince = [];
        foreach (DB::table('cities')->orderBy('id')->get(['id', 'name', 'province_id']) as $c) {
            $cityNames[(int) $c->id] = (string) $c->name;
            $cityProvince[(int) $c->id] = (int) $c->province_id;
        }

        $listingCounts = $this->listingCountsByCity();

        // Cities of each CANONICAL province, ordered by listing count desc then
        // id asc. CityNameMatcher takes the first exact hit, so when a province
        // holds two rows spelled the same, the one that carries listings wins —
        // linking the polygon to the empty twin would hide real inventory.
        $candidatesByCanonical = [];
        foreach ($cityNames as $cityId => $name) {
            $canonical = $provinceIdMap[$cityProvince[$cityId]] ?? $cityProvince[$cityId];
            $candidatesByCanonical[$canonical][$cityId] = $name;
        }
        foreach ($candidatesByCanonical as $canonical => $rows) {
            uksort($rows, function ($a, $b) use ($listingCounts) {
                $byCount = ($listingCounts[(int) $b] ?? 0) <=> ($listingCounts[(int) $a] ?? 0);

                return $byCount !== 0 ? $byCount : ((int) $a <=> (int) $b);
            });
            $candidatesByCanonical[$canonical] = $rows;
        }

        $nationwideUnique = $this->nationwideUniqueNames($cityNames);

        // ── Passes 1-3: which province is this polygon in? ─────────────────
        $boundaries = DB::table('boundaries')
            ->where('level', 'city')
            ->orderBy('id')
            ->get(['id', 'name', 'parent_name']);

        $province = [];   // boundary id => province id (raw, pre-canonical)
        $passCounts = ['contain' => 0, 'intersect' => 0, 'nearest' => 0, 'none' => 0];
        $spatialErrors = [];

        foreach ($this->containedProvinces() as $boundaryId => $provinceId) {
            $province[$boundaryId] = $provinceId;
            $passCounts['contain']++;
        }

        $leftovers = $boundaries->filter(fn ($b) => ! isset($province[(int) $b->id]));
        if ($leftovers->isNotEmpty()) {
            $this->line('  '.$leftovers->count().' polygon(s) left after centroid containment; trying overlap, then distance…');
        }

        foreach ($leftovers as $b) {
            $boundaryId = (int) $b->id;

            $provinceId = $this->largestOverlapProvince($boundaryId, $spatialErrors, (string) $b->name);
            if ($provinceId !== null) {
                $province[$boundaryId] = $provinceId;
                $passCounts['intersect']++;

                continue;
            }

            $provinceId = $this->nearestProvince($boundaryId, $spatialErrors, (string) $b->name);
            if ($provinceId !== null) {
                $province[$boundaryId] = $provinceId;
                $passCounts['nearest']++;

                continue;
            }

            $passCounts['none']++;
        }

        // ── Pass 4: name, inside the province group ────────────────────────
        // Three rounds, and the order is the whole safety argument.
        //
        //   A. exact name inside the polygon's own province. Many polygons may
        //      share one city (Manila's districts), so claims are not exclusive
        //      here — but every polygon that knows its own name gets to settle
        //      before any guess is allowed to run.
        //   B. near-miss inside the same province, against cities round A did
        //      not take. A guess never steals a city a name already earned.
        //   C. a last rescue for polygons that are STILL unmatched AND that no
        //      pass could place in a province: an exact match on a name that
        //      belongs to exactly one city in the whole country.
        //
        // Round C is last AND province-less, and both halves of that are
        // departures from the first sketch of this cascade. Both were forced by
        // the real data:
        //
        //   Running it second handed Cebu's "San Remigio" polygon — 13 listings
        //   — to a town in Antique, because Antique's row carries that exact
        //   spelling while Cebu's is spelled "San Remegio". The near-miss pass
        //   inside Cebu gets it right; it just never got to run.
        //
        //   Running it on polygons that DID get placed linked seven more
        //   polygons across province lines, every one of them for the same
        //   reason: the country has two Bontocs, two Liloans, two Malitbogs,
        //   and `cities` only ever had one row for each. The nationwide name
        //   was unique only because the other row is missing. Linking Southern
        //   Leyte's Bontoc polygon to Mountain Province's Bontoc would have
        //   painted one town's listing count onto two provinces.
        //
        // Province placement is geometric evidence. A nationwide name lookup is
        // a guess about where a polygon is. The guess never overrules the
        // evidence — it only speaks when there is no evidence at all.
        $cityLink = [];                     // boundary id => city id
        $nameCounts = ['exact' => 0, 'fuzzy' => 0, 'nationwide' => 0, 'none' => 0];
        $claimed = [];
        $deferred = [];

        foreach ($boundaries as $b) {
            $boundaryId = (int) $b->id;
            $canonical = isset($province[$boundaryId])
                ? ($provinceIdMap[$province[$boundaryId]] ?? $province[$boundaryId])
                : null;
            $candidates = $canonical !== null ? ($candidatesByCanonical[$canonical] ?? []) : [];

            $cityId = CityNameMatcher::match($candidates, (string) $b->name);

            if ($cityId !== null && $this->isExactMatch((string) $b->name, $cityNames[$cityId] ?? '')) {
                $cityLink[$boundaryId] = $cityId;
                $claimed[$cityId] = true;
                $nameCounts['exact']++;

                continue;
            }

            $deferred[] = [$boundaryId, (string) $b->name, $candidates];
        }

        $stillOpen = [];

        foreach ($deferred as [$boundaryId, $name, $candidates]) {
            $cityId = CityNameMatcher::match($candidates, $name, array_keys($claimed));

            if ($cityId === null) {
                $stillOpen[] = [$boundaryId, $name];

                continue;
            }

            $cityLink[$boundaryId] = $cityId;
            $claimed[$cityId] = true;
            $nameCounts['fuzzy']++;
        }

        foreach ($stillOpen as [$boundaryId, $name]) {
            // Empty candidate list, so only the matcher's nationwide stage can
            // fire; withheld entirely from polygons that know their province.
            $cityId = isset($province[$boundaryId])
                ? null
                : CityNameMatcher::match([], $name, array_keys($claimed), $nationwideUnique);

            if ($cityId === null) {
                $nameCounts['none']++;

                continue;
            }

            $cityLink[$boundaryId] = $cityId;
            $claimed[$cityId] = true;
            $nameCounts['nationwide']++;
        }

        // ── Report ─────────────────────────────────────────────────────────
        $report = $this->buildReport(
            dryRun: $dryRun,
            boundaries: $boundaries,
            province: $province,
            provinceNames: $provinceNames,
            cityLink: $cityLink,
            cityNames: $cityNames,
            cityProvince: $cityProvince,
            listingCounts: $listingCounts,
            passCounts: $passCounts,
            nameCounts: $nameCounts,
            spatialErrors: $spatialErrors,
        );

        $this->newLine();
        $this->line($report);

        $reportPath = $this->option('report');
        if ($reportPath) {
            @mkdir(dirname($reportPath), 0775, true);
            file_put_contents($reportPath, $report."\n");
            $this->info("Report written to {$reportPath}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN — nothing was written and heatmap:boundaries:ver was not bumped.');

            return self::SUCCESS;
        }

        // ── Write ──────────────────────────────────────────────────────────
        $this->writeLinks($boundaries, $province, $cityLink);
        $this->bumpBoundariesVersion();

        $this->newLine();
        $this->info('Links written.');

        return self::SUCCESS;
    }

    /**
     * Does EVERY canonical province own a polygon? If not, refuse to run.
     *
     * "Some province polygons exist" is not the same question. The importer
     * reports a feature that matched no province row and carries on with the
     * other 86, so a server whose `provinces` table differs from the one the
     * alias table was written against ends up with a province layer that is
     * complete enough to pass a count check and still missing a province.
     *
     * That single hole is the worst input this command can be given, because
     * nothing downstream can see it. Every polygon of the missing province
     * fails pass 1, then pass 2 hands it to whichever NEIGHBOUR it shares a
     * border sliver with (touching shapes always have some overlap), and pass 4
     * then matches its name inside that neighbour's candidate list — where
     * Philippine town names repeat constantly (nine San Joses, seven Santa
     * Cruzes, eight San Miguels), so an exact hit is likely, not rare. The run
     * ends with exit code 0 and one town's listing count painted onto another
     * province. Exactly the class of bug this command was written to end.
     *
     * So the answer must come from the canonical ids, not from a row count:
     * the duplicate province rows (82/83, 53/65) mean 83 rows own 80 polygons
     * and both numbers are correct.
     *
     * @param  array<int, string>  $provinceNames
     * @param  array<int, int>  $provinceIdMap
     */
    private function provinceLayerIsComplete(array $provinceNames, array $provinceIdMap): bool
    {
        $expected = array_values(array_unique($provinceIdMap));

        $covered = DB::table('boundaries')
            ->where('level', 'province')
            ->whereNotNull('province_id')
            ->distinct()
            ->pluck('province_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_diff($expected, $covered);

        if ($missing === []) {
            return true;
        }

        sort($missing);

        $this->newLine();
        $this->error(count($missing).' canonical province(s) own no polygon — their towns would be placed in a NEIGHBOUR:');
        foreach ($missing as $id) {
            $this->line('  - '.($provinceNames[$id] ?? '?')."  (provinces.id {$id})");
        }
        $this->warn('Re-run the province import and read its output; a feature that matched no province row is listed there.');
        $this->warn('  php artisan boundaries:import database/data/geo/geoBoundaries-PHL-ADM2_simplified.geojson --level=province');

        if (! $this->option('force')) {
            $this->warn('Refusing to run. Pass --force to accept mis-placed towns in those provinces.');

            return false;
        }

        $this->warn('--force given: continuing with an incomplete province layer.');

        return true;
    }

    /**
     * [city id => listing count] under the same definition the admin insight
     * tiles use, so "cities with listings but no polygon" means the same thing
     * here as it does on the map the boss is looking at.
     *
     * No agent scope: this is a data-quality question about the whole catalogue.
     *
     * @return array<int, int>
     */
    private function listingCountsByCity(): array
    {
        $rows = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->leftJoin('projects', function ($join) {
                $join->on('projects.id', '=', 'properties.project_id')
                    ->whereNull('projects.deleted_at');
            })
            ->leftJoin('barangays', 'barangays.id', '=', 'properties.address_id')
            ->leftJoin('cities as property_cities', 'property_cities.id', '=', 'barangays.city_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->where('properties.status', '!=', 'deleted')
            ->whereIn('categories.name', self::STANDARD_CATEGORIES)
            ->selectRaw('COALESCE(projects.city_id, property_cities.id) AS city_id, COUNT(*) AS total')
            ->groupBy(DB::raw('COALESCE(projects.city_id, property_cities.id)'))
            ->get();

        $counts = [];
        foreach ($rows as $r) {
            if ($r->city_id === null) {
                continue;   // listings that resolve to no city at all
            }
            $counts[(int) $r->city_id] = (int) $r->total;
        }

        return $counts;
    }

    /**
     * [normalized name => city id] for names belonging to exactly ONE city row
     * in the whole country.
     *
     * Round C uses this to rescue a polygon no pass could place in a province
     * at all, without ever risking the ten "San Jose" rows. Built with
     * normalize() and never aliasKey(): folding the candidate side would turn
     * every provincial Santa Cruz into Manila (see CityNameMatcher's warning).
     *
     * On the current data this index is a safety net that never has to catch
     * anything — every polygon gets a province. It is kept because a server
     * whose province import is incomplete should degrade to a name guess rather
     * than to a blank map.
     *
     * A side effect worth knowing: the duplicated Northern Samar province rows
     * give each of their 24 towns two city ids, so those names are not "unique"
     * and never reach round C. That costs nothing — round A already covers them,
     * because both province rows sit in the same canonical group.
     *
     * @param  array<int, string>  $cityNames
     * @return array<string, int>
     */
    private function nationwideUniqueNames(array $cityNames): array
    {
        $byKey = [];
        foreach ($cityNames as $id => $name) {
            $byKey[CityNameMatcher::normalize($name)][] = (int) $id;
        }

        $unique = [];
        foreach ($byKey as $key => $ids) {
            if ($key !== '' && count($ids) === 1) {
                $unique[$key] = $ids[0];
            }
        }

        return $unique;
    }

    /**
     * Pass 1 — every city polygon whose centroid falls inside a province polygon.
     *
     * One set-based query rather than 1,647 round trips. MBRContains is a cheap
     * bounding-box prefilter that the spatial index can answer; the exact
     * ST_Contains only runs on survivors. MIN(province_id) is a formality — a
     * point cannot be strictly inside two provinces, and the query is grouped so
     * that a degenerate border case still yields one deterministic answer
     * instead of duplicating the boundary.
     *
     * @return array<int, int> boundary id => province id
     */
    private function containedProvinces(): array
    {
        $rows = DB::select(
            "SELECT ct.id AS boundary_id, MIN(p.province_id) AS province_id
             FROM (SELECT id, ST_Centroid(geom) AS pt FROM boundaries WHERE level = 'city') ct
             JOIN boundaries p
               ON p.level = 'province'
              AND p.province_id IS NOT NULL
              AND MBRContains(p.geom, ct.pt)
              AND ST_Contains(p.geom, ct.pt)
             GROUP BY ct.id"
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->boundary_id] = (int) $r->province_id;
        }

        return $out;
    }

    /**
     * Pass 2 — the province this polygon shares the most area with.
     *
     * Catches the shapes pass 1 misses: a crescent-shaped coastal town whose
     * centroid lands in the sea, or a town whose centroid falls in a sliver of
     * the neighbouring province.
     *
     * Deliberately one query per candidate province instead of one ORDER BY
     * over all of them. ST_Intersection of two polygons that only touch returns
     * a GEOMETRYCOLLECTION, and ST_Area then raises "value is a geometry of
     * unexpected type GEOMCOLLECTION" — in a single statement that one bad pair
     * kills the whole ORDER BY and the polygon falls through to pass 3 for no
     * reason. Per pair, the same failure is simply what it means: the overlap is
     * a line or a point, so the area is zero and that province is not the
     * answer. Only polygons that pass 1 could not place get here (36 of 1,647),
     * and MBRIntersects narrows each one to a handful of provinces, so the extra
     * round trips are cheap.
     *
     * @param  string[]  $spatialErrors  collected by reference for the report
     */
    private function largestOverlapProvince(int $boundaryId, array &$spatialErrors, string $name): ?int
    {
        try {
            $candidates = DB::select(
                "SELECT p.id, p.province_id
                 FROM boundaries p
                 JOIN boundaries c ON c.id = ?
                 WHERE p.level = 'province'
                   AND p.province_id IS NOT NULL
                   AND MBRIntersects(p.geom, c.geom)",
                [$boundaryId]
            );
        } catch (\Throwable $e) {
            $spatialErrors[] = "MBRIntersects on '{$name}' (boundary {$boundaryId}): ".$this->briefly($e->getMessage());

            return null;
        }

        $best = null;
        $bestArea = 0.0;

        foreach ($candidates as $candidate) {
            try {
                $row = DB::selectOne(
                    'SELECT ST_Area(ST_Intersection(p.geom, c.geom)) AS overlap
                     FROM boundaries p JOIN boundaries c ON c.id = ?
                     WHERE p.id = ?',
                    [$boundaryId, (int) $candidate->id]
                );
            } catch (\Throwable $e) {
                // Touching-only overlap (GEOMETRYCOLLECTION) or an invalid ring.
                // Either way this province contributes no area; keep looking.
                $spatialErrors[] = "ST_Intersection on '{$name}' (boundary {$boundaryId}) vs province "
                    .$candidate->province_id.': '.$this->briefly($e->getMessage());

                continue;
            }

            $overlap = $row === null ? 0.0 : (float) $row->overlap;

            if ($overlap > $bestArea) {
                $bestArea = $overlap;
                $best = (int) $candidate->province_id;
            }
        }

        return $best;
    }

    /** First sentence of a SQLSTATE message — the rest is the whole query. */
    private function briefly(string $message): string
    {
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return mb_substr($message, 0, 160);
    }

    /**
     * Pass 3 — the nearest province polygon.
     *
     * Last geometric resort, for small offshore islands that overlap nothing.
     * Distance is in degrees (SRID 0) and used both for ordering and against
     * {@see self::NEAREST_PROVINCE_MAX_DEGREES}; the latitude distortion that
     * makes degrees a poor unit of length does not matter at that tolerance.
     *
     * Beyond the ceiling the answer is refused rather than returned. "Nearest"
     * with no ceiling cannot fail, and a province that is simply absent from
     * the layer would collect every orphan polygon in the country.
     *
     * @param  string[]  $spatialErrors  collected by reference for the report
     */
    private function nearestProvince(int $boundaryId, array &$spatialErrors, string $name): ?int
    {
        try {
            $row = DB::selectOne(
                "SELECT p.province_id, ST_Distance(p.geom, c.geom) AS dist
                 FROM boundaries p
                 JOIN boundaries c ON c.id = ?
                 WHERE p.level = 'province' AND p.province_id IS NOT NULL
                 ORDER BY dist ASC
                 LIMIT 1",
                [$boundaryId]
            );
        } catch (\Throwable $e) {
            $spatialErrors[] = "ST_Distance on '{$name}' (boundary {$boundaryId}): ".$this->briefly($e->getMessage());

            return null;
        }

        if ($row === null || $row->province_id === null) {
            return null;
        }

        $distance = (float) $row->dist;

        if ($distance > self::NEAREST_PROVINCE_MAX_DEGREES) {
            $this->warn(sprintf(
                "  '%s' (boundary %d) is %.2f° from the nearest province polygon — left unplaced rather than guessed.",
                $name,
                $boundaryId,
                $distance
            ));

            return null;
        }

        return (int) $row->province_id;
    }

    /**
     * Did the matcher land on this city by NAME, or by near-miss?
     *
     * Asked through the matcher's own public normalize()/aliasKey() so the two
     * stages can never drift apart: if the matched row's name squashes to the
     * boundary's plain form or to its alias target, it was an exact hit.
     */
    private function isExactMatch(string $boundaryName, string $cityName): bool
    {
        $city = CityNameMatcher::normalize($cityName);

        return $city !== '' && (
            $city === CityNameMatcher::normalize($boundaryName)
            || $city === CityNameMatcher::aliasKey($boundaryName)
        );
    }

    /**
     * One transaction: wipe this level's links, then write the computed ones.
     *
     * The wipe is what makes the command idempotent and authoritative — without
     * it a polygon that used to match and no longer does would keep pointing at
     * a city forever. Updates are grouped by (city_id, province_id) pair so the
     * 1,647 rows go out in a few dozen statements instead of 1,647.
     *
     * @param  Collection<int, object>  $boundaries
     * @param  array<int, int>  $province
     * @param  array<int, int>  $cityLink
     */
    private function writeLinks($boundaries, array $province, array $cityLink): void
    {
        $groups = [];
        foreach ($boundaries as $b) {
            $boundaryId = (int) $b->id;
            $key = ($cityLink[$boundaryId] ?? 'null').':'.($province[$boundaryId] ?? 'null');
            $groups[$key][] = $boundaryId;
        }

        DB::transaction(function () use ($groups) {
            DB::table('boundaries')
                ->where('level', 'city')
                ->update(['city_id' => null, 'province_id' => null]);

            $now = Carbon::now();

            foreach ($groups as $key => $ids) {
                [$cityId, $provinceId] = explode(':', $key);

                if ($cityId === 'null' && $provinceId === 'null') {
                    continue;   // already NULL from the wipe
                }

                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table('boundaries')->whereIn('id', $chunk)->update([
                        'city_id' => $cityId === 'null' ? null : (int) $cityId,
                        'province_id' => $provinceId === 'null' ? null : (int) $provinceId,
                        'updated_at' => $now,
                    ]);
                }
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
     * The whole point of the command, in text: what it did, what it could not
     * do, and whether the result is good enough to ship.
     *
     * Coverage is computed from the in-memory assignment rather than re-queried,
     * so --dry-run prints exactly the number a real run would produce.
     *
     * @param  Collection<int, object>  $boundaries
     * @param  array<int, int>  $province
     * @param  array<int, string>  $provinceNames
     * @param  array<int, int>  $cityLink
     * @param  array<int, string>  $cityNames
     * @param  array<int, int>  $cityProvince
     * @param  array<int, int>  $listingCounts
     * @param  array<string, int>  $passCounts
     * @param  array<string, int>  $nameCounts
     * @param  string[]  $spatialErrors
     */
    private function buildReport(
        bool $dryRun,
        $boundaries,
        array $province,
        array $provinceNames,
        array $cityLink,
        array $cityNames,
        array $cityProvince,
        array $listingCounts,
        array $passCounts,
        array $nameCounts,
        array $spatialErrors,
    ): string {
        $lines = [];
        $lines[] = '═══ boundaries:relink-cities '.($dryRun ? '— DRY RUN ' : '').'— '.Carbon::now()->toDateTimeString().' ═══';
        $lines[] = '';
        $lines[] = 'Province placement ('.$boundaries->count().' polygons)';
        $lines[] = sprintf('  1. centroid inside a province   %6d', $passCounts['contain']);
        $lines[] = sprintf('  2. largest area of overlap      %6d', $passCounts['intersect']);
        $lines[] = sprintf('  3. nearest province             %6d', $passCounts['nearest']);
        $lines[] = sprintf('     no province                  %6d', $passCounts['none']);
        $lines[] = '';
        $lines[] = 'City linking';
        $lines[] = sprintf('  4a. exact name in province      %6d', $nameCounts['exact']);
        $lines[] = sprintf('  4b. near-miss name in province  %6d', $nameCounts['fuzzy']);
        $lines[] = sprintf('  4c. nationwide-unique name      %6d', $nameCounts['nationwide']);
        $lines[] = sprintf('      no city match               %6d', $nameCounts['none']);

        if ($spatialErrors !== []) {
            $lines[] = '';
            $lines[] = 'Spatial calls that failed and were stepped over ('.count($spatialErrors).')';
            $lines[] = '  Expected noise: a touching-only overlap comes back as a GEOMETRYCOLLECTION and';
            $lines[] = '  ST_Area refuses it. Each line below cost the polygon one candidate, not its place.';
            foreach (array_slice($spatialErrors, 0, 20) as $e) {
                $lines[] = '  '.$e;
            }
            if (count($spatialErrors) > 20) {
                $lines[] = '  … '.(count($spatialErrors) - 20).' more.';
            }
        }

        // Unmatched boundaries, with the province they were placed in — that is
        // the first thing to look at when deciding whether a new alias is worth
        // adding, because it says WHERE to go looking for the right row.
        $unmatched = [];
        foreach ($boundaries as $b) {
            $boundaryId = (int) $b->id;
            if (isset($cityLink[$boundaryId])) {
                continue;
            }
            $provinceId = $province[$boundaryId] ?? null;
            $unmatched[] = sprintf(
                '  %-38s %s',
                $b->name,
                $provinceId !== null ? ($provinceNames[$provinceId] ?? "province {$provinceId}") : '(no province)'
            );
        }
        sort($unmatched);

        $lines[] = '';
        $lines[] = 'Unmatched boundaries ('.count($unmatched).') — these polygons shade nothing';
        foreach ($unmatched as $u) {
            $lines[] = $u;
        }

        // Cities that carry listings and still have no polygon. This is the list
        // that decides whether a CITY_ALIASES entry is worth adding: a town with
        // zero listings costs the map nothing.
        $linkedCityIds = array_flip(array_values($cityLink));
        $orphans = [];
        foreach ($listingCounts as $cityId => $count) {
            if (isset($linkedCityIds[$cityId])) {
                continue;
            }
            $provinceId = $cityProvince[$cityId] ?? null;
            $orphans[] = [
                'count' => $count,
                'line' => sprintf(
                    '  %7d  %-32s %-24s (city %d)',
                    $count,
                    $cityNames[$cityId] ?? '?',
                    $provinceId !== null ? ($provinceNames[$provinceId] ?? '?') : '?',
                    $cityId
                ),
            ];
        }
        usort($orphans, fn ($a, $b) => $b['count'] <=> $a['count']);

        $lines[] = '';
        $lines[] = 'Listing-bearing cities with NO polygon ('.count($orphans).')';
        if ($orphans === []) {
            $lines[] = '  none — every city that carries a listing has a shape.';
        }
        foreach (array_slice($orphans, 0, 40) as $o) {
            $lines[] = $o['line'];
        }
        if (count($orphans) > 40) {
            $lines[] = '  … '.(count($orphans) - 40).' more, all smaller.';
        }

        // Coverage — the number the heatmap lives or dies by.
        $totalListings = array_sum($listingCounts);
        $mapped = 0;
        foreach ($listingCounts as $cityId => $count) {
            if (isset($linkedCityIds[$cityId])) {
                $mapped += $count;
            }
        }
        $pct = $totalListings > 0 ? round(100 * $mapped / $totalListings, 2) : 0.0;

        $lines[] = '';
        $lines[] = 'Coverage (listings in a city that has a polygon)';
        $lines[] = sprintf('  %d of %d listings  =  %s%%', $mapped, $totalListings, number_format($pct, 2));
        $lines[] = '  Target >= 98%. Listings that resolve to no city at all are counted nowhere and';
        $lines[] = '  are excluded from both sides of this ratio.';

        // Cities owning several polygons. Expected and fine for Manila (16
        // districts) and genuine multipart towns; a long tail here would mean
        // the province stage went wrong.
        $perCity = array_count_values(array_map('intval', array_values($cityLink)));
        arsort($perCity);
        $multi = array_filter($perCity, fn ($n) => $n > 1);

        $lines[] = '';
        $lines[] = 'Cities linked to more than one polygon ('.count($multi).')';
        foreach (array_slice($multi, 0, 15, true) as $cityId => $n) {
            $provinceId = $cityProvince[$cityId] ?? null;
            $lines[] = sprintf(
                '  %3d  %-32s %-24s (city %d)',
                $n,
                $cityNames[$cityId] ?? '?',
                $provinceId !== null ? ($provinceNames[$provinceId] ?? '?') : '?',
                $cityId
            );
        }

        return implode("\n", $lines);
    }
}
