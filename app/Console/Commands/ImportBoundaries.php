<?php

namespace App\Console\Commands;

use App\Support\ProvinceCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Imports administrative boundary polygons (geoBoundaries GeoJSON) into the
 * `boundaries` table for the admin maps. Geometry is stored as SRID 0 so it
 * matches the listing polygon filter and works with ST_Simplify.
 *
 *   php artisan boundaries:import database/data/geo/geoBoundaries-PHL-ADM2_simplified.geojson --level=province
 *   php artisan boundaries:import storage/app/geoBoundaries-PHL-ADM3_simplified.geojson --level=city
 *   php artisan boundaries:import storage/app/geoBoundaries-PHL-ADM4_simplified.geojson --level=barangay
 *
 * The ADM2 (province) file is committed under database/data/geo/ with its
 * licence note; the larger ADM3/ADM4 files are not in version control, so copy
 * them to storage/app/ before importing those levels. See
 * database/data/geo/ATTRIBUTION.md.
 *
 * ── This command does NOT link city polygons to `cities` rows ───────────────
 * It used to, by normalised name with first-id-wins on a tie. That quietly
 * wrecked the data: Philippine town names repeat constantly, so ONE cities.id
 * ended up owning up to ten polygons scattered across the country (San Jose /
 * Batangas 10, San Isidro / Abra 9, San Miguel / Bohol 8), while 216 polygons
 * got no id at all and 22% of listings sat in a city with no shape. A name is
 * simply not enough information — the province the polygon sits in is, and only
 * geometry knows that.
 *
 * So the city import now leaves `city_id` NULL on purpose and
 * `boundaries:relink-cities` does the linking afterwards, geometry first. Do
 * not reintroduce name matching here: a re-import would silently regress every
 * link the relink command earned.
 */
class ImportBoundaries extends Command
{
    protected $signature = 'boundaries:import
        {file : Path to a geoBoundaries GeoJSON FeatureCollection}
        {--level=city : Boundary level — city|barangay|province}';

    protected $description = 'Import geoBoundaries polygons (province/city/barangay) for the admin maps';

    public function handle(): int
    {
        $level = $this->option('level');
        if (! in_array($level, ['city', 'barangay', 'province'], true)) {
            $this->error("--level must be 'city', 'barangay' or 'province'.");

            return self::FAILURE;
        }

        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        // ADM4 (barangay) can be large; a one-time CLI import can afford the memory.
        ini_set('memory_limit', '2G');

        $this->info("Reading {$file} …");
        $json = json_decode((string) file_get_contents($file), true);
        $features = $json['features'] ?? null;
        if (! is_array($features)) {
            $this->error('Invalid GeoJSON: no "features" array.');

            return self::FAILURE;
        }

        if ($level === 'province') {
            return $this->importProvinces($features);
        }

        // Name/parent property keys differ by source: geoBoundaries uses
        // shapeName; PSA/HDX COD-AB use ADM3_EN/ADM4_EN/NAME_3/NAME_4. Try the
        // level-appropriate ones first, then generic fallbacks.
        $nameKeys = $level === 'barangay'
            ? ['shapeName', 'ADM4_EN', 'NAME_4', 'adm4_en', 'brgy_name', 'BARANGAY', 'Name', 'name']
            : ['shapeName', 'ADM3_EN', 'NAME_3', 'adm3_en', 'MUNICIPAL', 'Name', 'name'];
        $parentKeys = $level === 'barangay'
            ? ['ADM3_EN', 'NAME_3', 'parentName']
            : ['ADM2_EN', 'NAME_2', 'parentName'];

        // Idempotent: replace this level. FKs block TRUNCATE, so delete by level.
        DB::table('boundaries')->where('level', $level)->delete();

        $total = count($features);
        $this->info("Importing {$total} {$level} feature(s)…");
        $bar = $this->output->createProgressBar($total);

        $skipped = 0;
        $imported = 0;
        $batch = [];
        $flush = function () use (&$batch, &$imported, &$skipped) {
            if (empty($batch)) {
                return;
            }
            try {
                $this->insertBatch($batch);
                $imported += count($batch);
            } catch (\Throwable $e) {
                // One bad geometry would fail the whole multi-row insert — retry
                // the batch row-by-row, skipping invalid features.
                foreach ($batch as $row) {
                    try {
                        $this->insertBatch([$row]);
                        $imported++;
                    } catch (\Throwable $e2) {
                        $skipped++;
                    }
                }
            }
            $batch = [];
        };

        foreach ($features as $f) {
            $bar->advance();
            $geometry = $f['geometry'] ?? null;
            $props = $f['properties'] ?? [];
            if (! is_array($geometry) || empty($geometry['type'])) {
                $skipped++;

                continue;
            }
            if (! in_array($geometry['type'], ['Polygon', 'MultiPolygon'], true)) {
                $skipped++;

                continue;
            }

            $name = '';
            foreach ($nameKeys as $nk) {
                if (! empty($props[$nk])) {
                    $name = (string) $props[$nk];
                    break;
                }
            }
            $name = trim($name) !== '' ? trim($name) : 'Unknown';
            $parent = null;
            foreach ($parentKeys as $pk) {
                if (! empty($props[$pk])) {
                    $parent = (string) $props[$pk];
                    break;
                }
            }

            $batch[] = [
                'level' => $level,
                'name' => $name,
                'parent_name' => $parent ? (string) $parent : null,
                // Linking is boundaries:relink-cities' job — see the class docblock.
                'city_id' => null,
                'province_id' => null,
                'barangay_id' => null,
                'geom' => json_encode($geometry),
            ];

            if (count($batch) >= 200) {
                $flush();
            }
        }
        $flush();

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Imported {$imported}, skipped {$skipped}.");

        $this->bumpBoundariesVersion();

        if ($level === 'city') {
            $this->newLine();
            $this->warn('city_id is NULL on every row just imported — nothing is linked yet.');
            $this->warn('Next: php artisan boundaries:relink-cities --dry-run   (then without --dry-run)');
        }

        return self::SUCCESS;
    }

    /**
     * Province level: canonicalise, DISSOLVE, one row per province row we own.
     *
     * The ADM2 file is a different administrative vocabulary from this
     * database's `provinces` table — it splits Metro Manila into four
     * congressional districts and ranks Cotabato City and the City of Isabela
     * as provinces. Painting those as-is would give the heatmap polygons that
     * can never hold a listing count, and leave Metro Manila as four unlabelled
     * slivers. So each feature is folded onto a real `provinces.id` by
     * {@see ProvinceCanonicalizer}, and every feature folded onto the same id is
     * UNIONed into a single multipolygon — one row, one province, one shaded
     * area. 87 features become 80 rows.
     *
     * The original file spellings are kept in `parent_name`, joined by ' | ', so
     * the dissolve is auditable from SQL alone without re-reading the file.
     *
     * @param  array<int, array<string, mixed>>  $features
     */
    private function importProvinces(array $features): int
    {
        $idToName = [];
        foreach (DB::table('provinces')->orderBy('id')->get(['id', 'name']) as $p) {
            $idToName[(int) $p->id] = (string) $p->name;
        }
        if ($idToName === []) {
            $this->error('The provinces table is empty — nothing to canonicalise against.');

            return self::FAILURE;
        }

        $idMap = ProvinceCanonicalizer::idMap($idToName);

        // canonical key => canonical province id. Several rows can share a key
        // (the duplicate Northern Samar / Samar rows); idMap already decided
        // which id wins, so taking the lowest canonical id here is a no-op that
        // just guards against an unexpected tie.
        $keyToId = [];
        foreach ($idToName as $id => $name) {
            $key = ProvinceCanonicalizer::key($name);
            $canonical = $idMap[$id] ?? $id;
            $keyToId[$key] = isset($keyToId[$key]) ? min($keyToId[$key], $canonical) : $canonical;
        }

        /** @var array<int, array{names: string[], geoms: array<int, array<string, mixed>>}> $groups */
        $groups = [];
        $unmatched = [];
        $skipped = 0;

        foreach ($features as $f) {
            $geometry = $f['geometry'] ?? null;
            $props = $f['properties'] ?? [];

            if (! is_array($geometry) || ! in_array($geometry['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
                $skipped++;

                continue;
            }

            $name = trim((string) ($props['shapeName'] ?? $props['ADM2_EN'] ?? $props['NAME_2'] ?? ''));
            if ($name === '') {
                $skipped++;

                continue;
            }

            $key = ProvinceCanonicalizer::key($name);
            $provinceId = $keyToId[$key] ?? null;

            if ($provinceId === null) {
                // Loud, not fatal: a province the map knows and this database
                // does not is a data question for a human, not a reason to
                // abandon the other 86 features.
                $unmatched[] = "{$name}  (canonical key '{$key}')";

                continue;
            }

            $groups[$provinceId]['names'][] = $name;
            $groups[$provinceId]['geoms'][] = $geometry;
        }

        if ($unmatched !== []) {
            $this->newLine();
            $this->error(count($unmatched).' feature(s) matched no province row and were NOT imported:');
            foreach ($unmatched as $u) {
                $this->line('  - '.$u);
            }
            $this->warn('Add an alias in App\Support\ProvinceCanonicalizer, or add the province row, then re-run.');
        }

        // Idempotent: replace this level. FKs block TRUNCATE, so delete by level.
        DB::table('boundaries')->where('level', 'province')->delete();

        $this->info('Dissolving '.count($features).' feature(s) into '.count($groups).' province row(s)…');
        $bar = $this->output->createProgressBar(count($groups));

        $imported = 0;
        $failed = [];
        $dissolveFallbacks = [];

        foreach ($groups as $provinceId => $group) {
            $bar->advance();

            $geomJson = $this->dissolve($group['geoms'], $fellBack);
            if ($fellBack) {
                $dissolveFallbacks[] = $idToName[$provinceId];
            }

            $row = [
                'level' => 'province',
                // The DB's own spelling, so the map label and the count label agree.
                'name' => $idToName[$provinceId],
                'parent_name' => implode(' | ', $group['names']),
                'city_id' => null,
                'province_id' => $provinceId,
                'barangay_id' => null,
                'geom' => $geomJson,
            ];

            try {
                $this->insertBatch([$row]);
                $imported++;
            } catch (\Throwable $e) {
                $failed[] = $idToName[$provinceId].': '.$e->getMessage();
            }
        }

        $bar->finish();
        $this->newLine(2);

        if ($dissolveFallbacks !== []) {
            $this->warn('ST_Union failed for '.count($dissolveFallbacks).' province(s); their parts were concatenated in PHP instead (shared borders stay as internal seams, which the map does not show): '.implode(', ', $dissolveFallbacks));
        }

        foreach ($failed as $f) {
            $this->error('Insert failed — '.$f);
        }

        $this->reportGeometryHealth();

        $this->info("Done. Imported {$imported} province row(s) from ".count($features).' feature(s)'
            .($skipped > 0 ? ", skipped {$skipped} non-polygon feature(s)" : '').'.');

        $this->bumpBoundariesVersion();

        return $failed === [] && $unmatched === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Merge several GeoJSON geometries into one, preferring a real spatial union.
     *
     * ST_Union is applied iteratively (binary, left-folded) because MySQL has no
     * aggregate ST_Union: there is no ST_Union(column) to GROUP BY with. It can
     * fail on self-intersecting rings — simplified source data is full of them —
     * so every step is guarded, and on the first failure the whole group falls
     * back to concatenating the polygon parts into one MultiPolygon in PHP.
     *
     * The fallback is honest rather than exact: the parts keep their shared
     * borders as internal seams instead of being welded. For a choropleth that
     * is invisible (the fill is one colour and the stroke follows the outer
     * ring of each part), and it is strictly better than dropping the province.
     *
     * @param  array<int, array<string, mixed>>  $geoms
     * @param  bool|null  $fellBack  set by reference: true when PHP concat was used
     */
    private function dissolve(array $geoms, ?bool &$fellBack = null): string
    {
        $fellBack = false;

        if (count($geoms) === 1) {
            return (string) json_encode($geoms[0]);
        }

        $accumulated = (string) json_encode($geoms[0]);

        for ($i = 1; $i < count($geoms); $i++) {
            $next = (string) json_encode($geoms[$i]);

            try {
                $row = DB::selectOne(
                    'SELECT ST_AsGeoJSON(ST_Union(ST_GeomFromGeoJSON(?, 1, 0), ST_GeomFromGeoJSON(?, 1, 0))) AS g',
                    [$accumulated, $next]
                );

                if ($row === null || ! is_string($row->g) || $row->g === '') {
                    throw new \RuntimeException('ST_Union returned no geometry');
                }

                $accumulated = $row->g;
            } catch (\Throwable $e) {
                $fellBack = true;

                return $this->concatMultiPolygon($geoms);
            }
        }

        return $accumulated;
    }

    /**
     * Pure-PHP fallback for {@see dissolve()}: every polygon of every input,
     * gathered into one MultiPolygon. No geometry library needed — a
     * MultiPolygon's coordinates are just the concatenation of its parts'.
     *
     * @param  array<int, array<string, mixed>>  $geoms
     */
    private function concatMultiPolygon(array $geoms): string
    {
        $parts = [];

        foreach ($geoms as $g) {
            if (($g['type'] ?? '') === 'Polygon') {
                $parts[] = $g['coordinates'];
            } elseif (($g['type'] ?? '') === 'MultiPolygon') {
                foreach ($g['coordinates'] as $polygon) {
                    $parts[] = $polygon;
                }
            }
        }

        return (string) json_encode(['type' => 'MultiPolygon', 'coordinates' => $parts]);
    }

    /**
     * Report province rows MySQL considers invalid, by name.
     *
     * Invalid geometry is not fatal here — ST_Simplify and ST_AsGeoJSON still
     * render it — but ST_Contains and ST_Intersection (which the relink command
     * leans on) can throw on it, so the operator gets the names up front rather
     * than a stack trace one command later.
     */
    private function reportGeometryHealth(): void
    {
        try {
            $invalid = DB::select(
                "SELECT name FROM boundaries WHERE level = 'province' AND ST_IsValid(geom) = 0 ORDER BY name"
            );
        } catch (\Throwable $e) {
            $this->warn('Could not run ST_IsValid on the imported rows: '.$e->getMessage());

            return;
        }

        if ($invalid === []) {
            $this->info('ST_IsValid: all province polygons valid.');

            return;
        }

        $this->warn('ST_IsValid: '.count($invalid).' invalid province polygon(s) — '
            .implode(', ', array_map(static fn ($r) => $r->name, $invalid)));
        $this->warn('boundaries:relink-cities guards every spatial call, so it will still run; expect more rows to fall through to its later passes.');
    }

    /**
     * Invalidate the heatmap's 24 h geometry cache.
     *
     * The cache key embeds this number, so bumping it is how a re-import
     * reaches a running server. The file driver (prod) has no cache tags and
     * flushing everything would evict unrelated work, hence a version pointer.
     * Written with forever(): increment() is a no-op on a key that does not
     * exist yet on several drivers.
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

    /** Multi-row INSERT with ST_GeomFromGeoJSON (SRID 0) for the geometry. */
    private function insertBatch(array $rows): void
    {
        $placeholders = [];
        $bindings = [];
        foreach ($rows as $r) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ST_GeomFromGeoJSON(?, 1, 0), NOW(), NOW())';
            array_push(
                $bindings,
                $r['level'],
                $r['name'],
                $r['parent_name'],
                $r['city_id'],
                $r['province_id'] ?? null,
                $r['barangay_id'],
                $r['geom']
            );
        }

        DB::statement(
            'INSERT INTO boundaries (level, name, parent_name, city_id, province_id, barangay_id, geom, created_at, updated_at) VALUES '
                .implode(', ', $placeholders),
            $bindings
        );
    }
}
