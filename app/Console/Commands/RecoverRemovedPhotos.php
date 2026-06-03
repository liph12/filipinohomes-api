<?php

namespace App\Console\Commands;

use App\Http\Controllers\RemovedPhotoUploadController;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recover photos for REMOVED (soft-deleted, photos_migration_note set) listings
 * from a CSV of the original photo URLs. The migration emptied these arrays and
 * kept no copy, so the originals come from an external CSV the admin holds.
 *
 * Mirrors ListingController::updateRemovedPhotos (the per-card flow) exactly:
 * re-hosts each URL to the new bucket via RemovedPhotoUploadController, dedups
 * shared images, drops dead URLs. The listing STAYS soft-deleted/removed — this
 * only writes the arrays. Runs inline; no queue worker needed.
 */
class RecoverRemovedPhotos extends Command
{
    protected $signature = 'listings:recover-removed-photos
                            {csv               : Path to the CSV file (header row: code, featured_photo, photos)}
                            {--no-redownload   : Save raw URLs as-is instead of re-hosting to the new bucket}
                            {--dry-run         : Parse + report only; no S3 puts / no DB writes}
                            {--skip-existing   : Skip listings whose featured_photo already holds a new-bucket URL}
                            {--chunk=200       : Rows between progress lines}';

    protected $description = 'Recover photos for REMOVED listings from a CSV (code + original photo URLs). Re-hosts to the new bucket; keeps listings soft-deleted. Inline, no queue.';

    /** New bucket hostnames — a featured_photo containing one is already recovered. */
    private const NEW_BUCKET_HOSTS = [
        'filipinohomes123.s3.ap-southeast-1.amazonaws.com',
        'filipinohomes123.s3.amazonaws.com',
    ];

    public function handle(): int
    {
        $path         = (string) $this->argument('csv');
        $redownload   = ! (bool) $this->option('no-redownload');
        $dryRun        = (bool) $this->option('dry-run');
        $skipExisting = (bool) $this->option('skip-existing');
        $chunk        = max(1, (int) $this->option('chunk'));

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("CSV not found or unreadable: {$path}");
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%sMode: %s | skip-existing: %s',
            $dryRun ? '[DRY RUN] ' : '',
            $redownload ? 're-host to new bucket' : 'save raw URLs',
            $skipExisting ? 'yes' : 'no',
        ));

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open: {$path}");
            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $this->error('CSV is empty.');
            return self::FAILURE;
        }
        $cols = [];
        foreach ($header as $i => $name) {
            $cols[strtolower(trim((string) $name))] = $i;
        }
        if (! isset($cols['code'])) {
            fclose($handle);
            $this->error("CSV must have a 'code' column.");
            return self::FAILURE;
        }
        $hasFeaturedCol = isset($cols['featured_photo']);
        $hasPhotosCol   = isset($cols['photos']);
        if (! $hasFeaturedCol && ! $hasPhotosCol) {
            fclose($handle);
            $this->error("CSV must have at least one of 'featured_photo' / 'photos'.");
            return self::FAILURE;
        }

        $uploader = $redownload ? app(RemovedPhotoUploadController::class) : null;

        $rowsParsed = $matched = $recovered = $skipped = $rehosted = 0;
        $unmatched  = [];
        $failedUrls = []; // ['code'=>, 'url'=>, 'reason'=>]

        $clean = fn (array $urls) => array_values(array_filter(
            array_map(fn ($u) => is_string($u) ? trim($u) : $u, $urls),
            fn ($u) => is_string($u) && $u !== '',
        ));

        $rowNo = 1; // header consumed
        while (($row = fgetcsv($handle)) !== false) {
            $rowNo++;
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            $rowsParsed++;

            $code = trim((string) ($row[$cols['code']] ?? ''));
            if ($code === '') {
                $this->line("  row {$rowNo}: blank code, skipped");
                continue;
            }

            $featuredIn = $hasFeaturedCol ? $clean($this->parsePhotoCell($row[$cols['featured_photo']] ?? '')) : [];
            $photosIn   = $hasPhotosCol   ? $clean($this->parsePhotoCell($row[$cols['photos']] ?? ''))   : [];

            $listing = Listing::onlyTrashed()
                ->whereNotNull('photos_migration_note')
                ->where('code', $code)
                ->first();

            if (! $listing) {
                $unmatched[] = $code;
                $this->line("  row {$rowNo}: code={$code} no removed listing matched");
                continue;
            }
            $matched++;

            if ($skipExisting && $this->hasNewBucketUrl($listing->featured_photo)) {
                $skipped++;
                $this->line("  row {$rowNo}: code={$code} already recovered, skipped");
                continue;
            }

            if (empty($featuredIn) && empty($photosIn)) {
                $this->line("  row {$rowNo}: code={$code} no URLs in row, skipped");
                continue;
            }

            // ── Build old→new map (mirror updateRemovedPhotos) ──
            if ($redownload) {
                $map = [];
                foreach (array_values(array_unique(array_merge($featuredIn, $photosIn))) as $url) {
                    if ($dryRun) {
                        $map[$url] = $url; // pretend success; no S3 write
                        continue;
                    }
                    try {
                        $map[$url] = $uploader->uploadFromUrl($url, '/filipinohomes-new');
                        $rehosted++;
                    } catch (Throwable $e) {
                        $map[$url] = null;
                        $failedUrls[] = ['code' => $code, 'url' => $url, 'reason' => $e->getMessage()];
                        Log::warning('recover-removed-photos redownload failed', [
                            'code' => $code, 'listing_id' => $listing->id,
                            'url' => $url, 'error' => $e->getMessage(),
                        ]);
                        $this->line("    fail url={$url}: {$e->getMessage()}");
                    }
                }
                $applyMap = fn (array $urls) => array_values(array_filter(
                    array_map(fn ($u) => $map[$u] ?? null, $urls),
                ));
                $featuredOut = $applyMap($featuredIn);
                $photosOut   = $applyMap($photosIn);
            } else {
                $featuredOut = $featuredIn;
                $photosOut   = $photosIn;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '  DRY row %d code=%s listing=%d featured:%d→%d photos:%d→%d',
                    $rowNo, $code, $listing->id,
                    count($featuredIn), count($featuredOut),
                    count($photosIn), count($photosOut),
                ));
                $recovered++;
                continue;
            }

            // ── Persist: saveQuietly, withTrashed, stay soft-deleted, keep note ──
            try {
                DB::transaction(function () use ($listing, $featuredOut, $photosOut, $hasPhotosCol) {
                    $listing->featured_photo = $featuredOut;
                    $listing->saveQuietly();

                    // Strict parity: only write property.photos when the CSV
                    // actually supplied a photos column.
                    if ($hasPhotosCol) {
                        $property = $listing->property()->withTrashed()->first();
                        if ($property) {
                            $property->photos = $photosOut;
                            $property->saveQuietly();
                        }
                    }
                });
                $recovered++;
                $this->line(sprintf(
                    '  OK row %d code=%s featured=%d photos=%d (still removed)',
                    $rowNo, $code, count($featuredOut), count($photosOut),
                ));
            } catch (Throwable $e) {
                Log::warning('recover-removed-photos save failed', [
                    'code' => $code, 'listing_id' => $listing->id, 'error' => $e->getMessage(),
                ]);
                $this->error("  row {$rowNo}: code={$code} SAVE FAILED: {$e->getMessage()}");
            }

            if ($rowsParsed % $chunk === 0) {
                $this->info("… processed {$rowsParsed} rows (recovered {$recovered}, rehosted {$rehosted})");
            }
        }
        fclose($handle);

        // ── Summary ──
        $this->newLine();
        $this->info('──────── Recovery summary ────────');
        $this->line("Rows parsed:        {$rowsParsed}");
        $this->line("Matched (removed):  {$matched}");
        $this->line("Skipped (existing): {$skipped}");
        $this->line(($dryRun ? 'Would recover:      ' : 'Listings recovered: ') . $recovered);
        $this->line("Photos re-hosted:   {$rehosted}");
        $this->line('Failed URLs:        ' . count($failedUrls));
        foreach ($failedUrls as $f) {
            $this->line("  [{$f['code']}] {$f['url']} — {$f['reason']}");
        }
        $this->line('Unmatched codes:    ' . count($unmatched));
        if ($unmatched) {
            $this->line('  ' . implode(', ', array_slice($unmatched, 0, 50))
                . (count($unmatched) > 50 ? ' …' : ''));
        }

        return self::SUCCESS;
    }

    /** Parse a photo cell: JSON array first, else split on newlines / | / , and trim noise. */
    private function parsePhotoCell(mixed $cell): array
    {
        $cell = trim((string) $cell);
        if ($cell === '') {
            return [];
        }

        $decoded = json_decode($cell, true);
        if (is_array($decoded)) {
            return array_values(array_filter(
                array_map(fn ($u) => is_string($u) ? trim($u) : $u, $decoded),
                fn ($u) => is_string($u) && $u !== '',
            ));
        }

        $parts = preg_split('/[\r\n|,]+/', $cell) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim(trim((string) $p), "[]\"' \t");
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    private function hasNewBucketUrl(mixed $value): bool
    {
        $urls = is_array($value) ? $value : (array) $value;
        foreach ($urls as $u) {
            if (! is_string($u)) {
                continue;
            }
            foreach (self::NEW_BUCKET_HOSTS as $host) {
                if (str_contains($u, $host)) {
                    return true;
                }
            }
        }
        return false;
    }
}
