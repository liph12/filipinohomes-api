<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports administrative boundary polygons (geoBoundaries GeoJSON) into the
 * `boundaries` table for the admin map. Geometry is stored as SRID 0 so it
 * matches the listing polygon filter and works with ST_Simplify.
 *
 * Download the *simplified* geoBoundaries files first (this env has no internet):
 *   php artisan boundaries:import storage/app/geoBoundaries-PHL-ADM3_simplified.geojson --level=city
 *   php artisan boundaries:import storage/app/geoBoundaries-PHL-ADM4_simplified.geojson --level=barangay
 */
class ImportBoundaries extends Command
{
    protected $signature = 'boundaries:import
        {file : Path to a geoBoundaries GeoJSON FeatureCollection}
        {--level=city : Boundary level — city|barangay}';

    protected $description = 'Import geoBoundaries polygons (city/barangay) for the admin map';

    public function handle(): int
    {
        $level = $this->option('level');
        if (! in_array($level, ['city', 'barangay'], true)) {
            $this->error("--level must be 'city' or 'barangay'.");

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

        // Best-effort name → id match. Cities only (barangay names collide across
        // cities and the ADM4 file rarely carries a parent, so leave barangay_id
        // null — the boundary_id filter uses the boundary's own geometry anyway).
        $cityByName = [];
        if ($level === 'city') {
            foreach (DB::table('cities')->get(['id', 'name']) as $c) {
                $cityByName[$this->normalize($c->name)] ??= $c->id;
            }
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

        $matched = 0;
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

            $cityId = null;
            if ($level === 'city') {
                $cityId = $cityByName[$this->normalize($name)] ?? null;
                if ($cityId) {
                    $matched++;
                }
            }

            $batch[] = [
                'level' => $level,
                'name' => $name,
                'parent_name' => $parent ? (string) $parent : null,
                'city_id' => $cityId,
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
        $this->info("Done. Imported {$imported}, skipped {$skipped}" . ($level === 'city' ? ", matched {$matched} to cities." : '.'));

        return self::SUCCESS;
    }

    /** Multi-row INSERT with ST_GeomFromGeoJSON (SRID 0) for the geometry. */
    private function insertBatch(array $rows): void
    {
        $placeholders = [];
        $bindings = [];
        foreach ($rows as $r) {
            $placeholders[] = '(?, ?, ?, ?, ?, ST_GeomFromGeoJSON(?, 1, 0), NOW(), NOW())';
            array_push($bindings, $r['level'], $r['name'], $r['parent_name'], $r['city_id'], $r['barangay_id'], $r['geom']);
        }

        DB::statement(
            'INSERT INTO boundaries (level, name, parent_name, city_id, barangay_id, geom, created_at, updated_at) VALUES '
                . implode(', ', $placeholders),
            $bindings
        );
    }

    /** Normalize a place name for matching: lowercase, strip accents, drop
     *  "city/municipality of" noise, keep alphanumerics. */
    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($translit !== false) {
            $s = $translit;
        }
        $s = preg_replace('/\b(city of|municipality of|city|municipality)\b/', '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '', $s);

        return $s;
    }
}
