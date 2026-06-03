<?php

namespace Database\Seeders;

use App\Http\Controllers\RemovedPhotoUploadController;
use App\Models\Listing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recover photos for REMOVED (soft-deleted, photos_migration_note set) listings
 * from an exported PHP array of the original photo URLs.
 *
 * Data source: database/data/listings.php — must define:
 *   $l = array(
 *     array('code' => 'CEB-25656',
 *           'featured_photo' => '["https://.../a.jpg"]',     // JSON string
 *           'photos'         => '["https://.../a.jpg", ...]'),// JSON string
 *     ...
 *   );
 *
 * Per row: matched by code (removed listings only). Each URL is re-hosted to the
 * new bucket via RemovedPhotoUploadController::uploadFromUrl (HTTP GET → WebP
 * ≤ 50 KB → filipinohomes123). URLs already on the new bucket are kept as-is.
 * featured_photo + property.photos are updated; the listing STAYS removed.
 *
 * Idempotent: re-running skips listings whose featured_photo is already on the
 * new bucket. Runs inline — no queue worker needed.
 */
class RecoverRemovedPhotosSeeder extends Seeder
{
    private const NEW_BUCKET_HOSTS = [
        'filipinohomes123.s3.ap-southeast-1.amazonaws.com',
        'filipinohomes123.s3.amazonaws.com',
    ];

    public function run(): void
    {
        $dataFile = database_path('data/listings.php');
        if (! is_file($dataFile)) {
            $this->command->error("Data file not found: {$dataFile}");
            $this->command->line('Save your export there so it defines: $l = array( array(\'code\'=>..., \'featured_photo\'=>..., \'photos\'=>...), ... );');
            return;
        }

        require $dataFile; // defines $l in this scope
        if (! isset($l) || ! is_array($l)) {
            $this->command->error('Data file must define an array variable $l.');
            return;
        }

        $uploader = app(RemovedPhotoUploadController::class);

        $total = count($l);
        $matched = $recovered = $rehosted = $skipped = 0;
        $unmatched = [];
        $failed = 0;

        $this->command->info("Recovering photos for {$total} row(s)…");
        $bar = $this->command->getOutput()->createProgressBar($total);
        $bar->start();

        foreach ($l as $row) {
            $bar->advance();

            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $featuredIn = $this->decode($row['featured_photo'] ?? null);
            $photosIn   = $this->decode($row['photos'] ?? null);

            $listing = Listing::onlyTrashed()
                ->whereNotNull('photos_migration_note')
                ->where('code', $code)
                ->first();

            if (! $listing) {
                $unmatched[] = $code;
                continue;
            }
            $matched++;

            // Idempotent re-runs: already recovered (featured on new bucket).
            if ($this->hasNewBucketUrl($listing->featured_photo)) {
                $skipped++;
                continue;
            }

            if (empty($featuredIn) && empty($photosIn)) {
                continue;
            }

            // Build old→new map. Already-new-bucket URLs are kept as-is (no
            // re-download). Dead URLs map to null and are dropped.
            $map = [];
            foreach (array_values(array_unique(array_merge($featuredIn, $photosIn))) as $url) {
                if ($this->isNewBucketUrl($url)) {
                    $map[$url] = $url;
                    continue;
                }
                try {
                    $map[$url] = $uploader->uploadFromUrl($url, '/filipinohomes-new');
                    $rehosted++;
                } catch (Throwable $e) {
                    $map[$url] = null;
                    $failed++;
                    Log::warning('recover-removed-photos (seeder) failed', [
                        'code' => $code, 'listing_id' => $listing->id,
                        'url' => $url, 'error' => $e->getMessage(),
                    ]);
                }
            }

            // Apply map, drop dropped/dead URLs, and dedup the result so a
            // repeated source (common in the export) isn't stored twice.
            $apply = fn (array $urls) => array_values(array_unique(array_filter(
                array_map(fn ($u) => $map[$u] ?? null, $urls),
            )));
            $featuredOut = $apply($featuredIn);
            $photosOut   = $apply($photosIn);

            try {
                DB::transaction(function () use ($listing, $featuredOut, $photosOut) {
                    $listing->featured_photo = $featuredOut;
                    $listing->saveQuietly();

                    $property = $listing->property()->withTrashed()->first();
                    if ($property) {
                        $property->photos = $photosOut;
                        $property->saveQuietly();
                    }
                });
                $recovered++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('recover-removed-photos (seeder) save failed', [
                    'code' => $code, 'listing_id' => $listing->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info('──────── Recovery summary ────────');
        $this->command->line("Rows:               {$total}");
        $this->command->line("Matched (removed):  {$matched}");
        $this->command->line("Recovered:          {$recovered}");
        $this->command->line("Skipped (existing): {$skipped}");
        $this->command->line("Photos re-hosted:   {$rehosted}");
        $this->command->line("Failed URLs/saves:  {$failed}");
        $this->command->line('Unmatched codes:    ' . count($unmatched));
        if ($unmatched) {
            $this->command->line('  ' . implode(', ', array_slice($unmatched, 0, 50))
                . (count($unmatched) > 50 ? ' …' : ''));
        }
    }

    /** A photo cell is a JSON-encoded array string (or already an array). */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            $arr = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $arr = is_array($decoded) ? $decoded : [$value];
        } else {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($u) => is_string($u) ? trim($u) : $u, $arr),
            fn ($u) => is_string($u) && $u !== '',
        ));
    }

    private function isNewBucketUrl(string $url): bool
    {
        foreach (self::NEW_BUCKET_HOSTS as $host) {
            if (str_contains($url, $host)) {
                return true;
            }
        }
        return false;
    }

    private function hasNewBucketUrl(mixed $value): bool
    {
        foreach ((is_array($value) ? $value : (array) $value) as $u) {
            if (is_string($u) && $this->isNewBucketUrl($u)) {
                return true;
            }
        }
        return false;
    }
}
