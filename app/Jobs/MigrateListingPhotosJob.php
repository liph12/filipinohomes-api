<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Migrates photos for ONE property and all its linked listings in a single
 * transaction.
 *
 * Why per-property and not per-listing: `Listing.featured_photo` URLs are
 * a subset of the linked `Property.photos`. If we processed each listing
 * independently, the same source image would be re-uploaded to S3 with a
 * different UUID for every listing that referenced it — and once
 * `Property.photos` is rewritten with new URLs, subsequent listings
 * couldn't match their old `featured_photo` URLs to the property's new
 * ones. Doing everything for a property in one transaction means each
 * source URL is migrated exactly once and the same `oldUrl → newUrl` map
 * is applied to both the property and all its listings.
 *
 * The job can also be invoked with `propertyId = null` and a list of
 * `orphanListingIds` to handle listings that have no `property_id`
 * (rare); those only get their `featured_photo` migrated.
 *
 * Constraints honoured (from the plan):
 *  - Never deletes source files (CopyObject / PutObject only).
 *  - Skips 0-byte sources (Storage::size for legacy bucket, HEAD +
 *    body-length for HTTP).
 *  - Drops dead URLs from arrays (no placeholder).
 *  - Soft-deletes any listing whose surviving featured_photo AND
 *    property photos are both empty after filtering.
 *
 * Disks used:
 *  - 's3'      → NEW bucket `filipinohomes123` (write target)
 *  - 's3_new'  → OLD bucket `filipinohomes`    (read source; legacy name)
 */
class MigrateListingPhotosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 600;

    /**
     * Flat output prefix in the new bucket. Mirrors the ImageUploadController
     * pattern (single folder, UUID filename, .webp). Every migrated photo —
     * regardless of its source (old S3, googleusercontent, anywhere) — ends
     * up here.
     */
    private const DEST_PREFIX = 'filipinohomes-compressed-from-old';

    /** Target size per migrated image (mirrors ImageUploadController). */
    private const TARGET_BYTES = 50 * 1024;
    private const SCALE_DOWN_WIDTH = 1200;
    private const INITIAL_QUALITY = 92;
    private const MIN_QUALITY = 4;

    /** New bucket hostnames — URLs containing these are already migrated. */
    private const NEW_BUCKET_HOSTS = [
        'filipinohomes123.s3.ap-southeast-1.amazonaws.com',
        'filipinohomes123.s3.amazonaws.com',
    ];

    /** Old bucket markers — eligible for read via `s3_new` disk. */
    private const OLD_BUCKET_HOSTS = [
        's3-ap-southeast-1.amazonaws.com/filipinohomes/',
        'filipinohomes.s3.ap-southeast-1.amazonaws.com',
        'filipinohomes.s3.amazonaws.com',
    ];

    /**
     * @param  int|null            $propertyId        Property to migrate (with all its listings).
     * @param  array<int>          $orphanListingIds  Listing IDs without a property to migrate alone (only used when $propertyId is null).
     * @param  bool                $dryRun
     */
    public function __construct(
        public ?int $propertyId = null,
        public array $orphanListingIds = [],
        public bool $dryRun = false,
    ) {
    }

    public function handle(): void
    {
        $this->process(null);
    }

    public function handleInline(?Command $cmd = null): void
    {
        $this->process($cmd);
    }

    private function process(?Command $cmd): void
    {
        if ($this->propertyId !== null) {
            $this->processProperty($this->propertyId, $cmd);
            return;
        }
        foreach ($this->orphanListingIds as $listingId) {
            $this->processOrphanListing((int) $listingId, $cmd);
        }
    }

    /* ─────────────────────────  Property + its listings  ───────────────────────── */

    private function processProperty(int $propertyId, ?Command $cmd): void
    {
        DB::transaction(function () use ($propertyId, $cmd) {
            $property = Property::lockForUpdate()->find($propertyId);
            if (!$property) {
                $this->say($cmd, "skip property={$propertyId} (not found or soft-deleted)");
                return;
            }

            $listings = Listing::where('property_id', $propertyId)
                ->lockForUpdate()
                ->get();

            $propertyAlreadyDone = $property->photos_migrated_at !== null;

            // ── Build the unified URL map ─────────────────────────────
            // Keys = every unique input URL across the property and all its
            // listings. Values = the resolved new URL, or null if dropped
            // (0-byte / dead / unsupported).
            $propertyIn = $this->normalizeArray($property->photos);

            $allInputUrls = $propertyAlreadyDone ? [] : array_values(array_unique($propertyIn));
            $perListingIn = [];
            foreach ($listings as $listing) {
                $featIn = $this->normalizeArray($listing->featured_photo);
                $perListingIn[$listing->id] = $featIn;
                if (!$propertyAlreadyDone) {
                    foreach ($featIn as $u) {
                        if (!in_array($u, $allInputUrls, true)) {
                            $allInputUrls[] = $u;
                        }
                    }
                }
            }

            $urlMap = []; // oldUrl => newUrl|null

            if ($propertyAlreadyDone) {
                // Property already migrated — its `photos` array already
                // holds the new URLs. We only need to fix up listings whose
                // featured_photo is still stale. We can't recover the
                // original mapping for stale URLs (the old URL is gone from
                // the property), so for those we just drop them. Anything
                // already on the new bucket stays.
                $this->say($cmd, "property={$propertyId} already migrated; reconciling listings only");
            } else {
                foreach ($allInputUrls as $url) {
                    try {
                        $urlMap[$url] = $this->migrateOne($url, $cmd);
                    } catch (Throwable $e) {
                        Log::warning('listings:migrate-photos url failed', [
                            'property_id' => $propertyId,
                            'url' => $url,
                            'error' => $e->getMessage(),
                        ]);
                        $this->say($cmd, "  fail url={$url}: {$e->getMessage()}");
                        $urlMap[$url] = null;
                    }
                }
            }

            // ── Compute outputs ───────────────────────────────────────
            $propertyOut = $propertyAlreadyDone
                ? $propertyIn
                : $this->applyMap($propertyIn, $urlMap);

            $perListingOut = [];
            foreach ($listings as $listing) {
                $featIn = $perListingIn[$listing->id];
                if ($propertyAlreadyDone) {
                    // Keep only URLs already on the new bucket — others are
                    // stale and unrecoverable now that the property is done.
                    $perListingOut[$listing->id] = array_values(array_filter(
                        $featIn,
                        fn ($u) => $this->isNewBucketUrl($u),
                    ));
                } else {
                    $perListingOut[$listing->id] = $this->applyMap($featIn, $urlMap);
                }
            }

            // ── Dry run report ────────────────────────────────────────
            if ($this->dryRun) {
                $this->say($cmd, sprintf(
                    'DRY property=%d photos: %d→%d',
                    $propertyId,
                    count($propertyIn),
                    count($propertyOut),
                ));
                foreach ($listings as $listing) {
                    $allEmpty = empty($perListingOut[$listing->id]) && empty($propertyOut);
                    $this->say($cmd, sprintf(
                        '  DRY listing=%d featured: %d→%d, all_empty=%s',
                        $listing->id,
                        count($perListingIn[$listing->id]),
                        count($perListingOut[$listing->id]),
                        $allEmpty ? 'YES (would soft-delete)' : 'no',
                    ));
                }
                return;
            }

            // ── Persist property ──────────────────────────────────────
            if (!$propertyAlreadyDone) {
                $property->photos = $propertyOut;
                $property->photos_migrated_at = now();
                $property->save();
            }

            // ── Persist listings ──────────────────────────────────────
            foreach ($listings as $listing) {
                $featOut = $perListingOut[$listing->id];
                $allEmpty = empty($featOut) && empty($propertyOut);

                $listing->featured_photo = $featOut;
                $listing->photos_migrated_at = now();

                if ($allEmpty) {
                    $listing->photos_migration_note = 'all_empty_soft_deleted';
                    $listing->save();
                    // Only soft-delete if not already soft-deleted.
                    if ($listing->deleted_at === null) {
                        $listing->delete();
                        $this->say($cmd, "  SOFT-DELETED listing={$listing->id}");
                    }
                    continue;
                }

                $listing->save();
                $this->say($cmd, sprintf(
                    '  OK listing=%d featured=%d',
                    $listing->id,
                    count($featOut),
                ));
            }
        });
    }

    /* ─────────────────────────  Orphan listing (no property)  ───────────────── */

    private function processOrphanListing(int $listingId, ?Command $cmd): void
    {
        DB::transaction(function () use ($listingId, $cmd) {
            $listing = Listing::lockForUpdate()->find($listingId);
            if (!$listing) {
                $this->say($cmd, "skip orphan listing={$listingId} (not found or soft-deleted)");
                return;
            }
            if ($listing->property_id !== null) {
                $this->say($cmd, "skip listing={$listingId} (has property_id; route via property job)");
                return;
            }

            $featuredIn = $this->normalizeArray($listing->featured_photo);

            $urlMap = [];
            foreach (array_values(array_unique($featuredIn)) as $url) {
                try {
                    $urlMap[$url] = $this->migrateOne($url, $cmd);
                } catch (Throwable $e) {
                    Log::warning('listings:migrate-photos url failed', [
                        'listing_id' => $listingId,
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                    $this->say($cmd, "  fail url={$url}: {$e->getMessage()}");
                    $urlMap[$url] = null;
                }
            }
            $featuredOut = $this->applyMap($featuredIn, $urlMap);

            if ($this->dryRun) {
                $this->say($cmd, sprintf(
                    'DRY orphan listing=%d featured: %d→%d, all_empty=%s',
                    $listing->id,
                    count($featuredIn),
                    count($featuredOut),
                    empty($featuredOut) ? 'YES (would soft-delete)' : 'no',
                ));
                return;
            }

            $listing->featured_photo = $featuredOut;
            $listing->photos_migrated_at = now();

            if (empty($featuredOut)) {
                $listing->photos_migration_note = 'all_empty_soft_deleted';
                $listing->save();
                if ($listing->deleted_at === null) {
                    $listing->delete();
                    $this->say($cmd, "SOFT-DELETED orphan listing={$listing->id}");
                }
                return;
            }

            $listing->save();
            $this->say($cmd, "OK orphan listing={$listing->id} featured=" . count($featuredOut));
        });
    }

    /* ─────────────────────────  URL migration primitives  ──────────────────── */

    /**
     * Fetch the source bytes, compress to WebP ≤ TARGET_BYTES, upload to the
     * new bucket under DEST_PREFIX with a UUID filename. Returns the new URL
     * or null if the URL was dropped (0-byte, dead, non-image, etc.).
     *
     * Compression mirrors ImageUploadController::handleS3Upload — scale down
     * to 1200px wide, then step quality from 92 down by 4 until under
     * threshold or quality floor is hit.
     */
    private function migrateOne(string $url, ?Command $cmd): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if ($this->isNewBucketUrl($url)) {
            return $url;
        }

        $bytes = $this->fetchSourceBytes($url, $cmd);
        if ($bytes === null) {
            return null;
        }

        try {
            $webp = $this->compressToWebp($bytes);
        } catch (Throwable $e) {
            $this->say($cmd, "  skip url={$url} (compress failed: {$e->getMessage()})");
            return null;
        }
        if (strlen($webp) === 0) {
            $this->say($cmd, "  skip url={$url} (compress produced empty output)");
            return null;
        }

        $destKey = sprintf('%s/%s.webp', self::DEST_PREFIX, (string) Str::uuid());

        if ($this->dryRun) {
            return $this->newBucketUrl($destKey);
        }

        $ok = Storage::disk('s3')->put($destKey, $webp, 'public');
        if (!$ok) {
            $this->say($cmd, "  fail put key={$destKey}");
            return null;
        }

        $this->say($cmd, sprintf(
            '  migrated %dB→%dB(webp) → %s',
            strlen($bytes),
            strlen($webp),
            $destKey,
        ));
        return Storage::disk('s3')->url($destKey);
    }

    /**
     * Fetches raw image bytes from the source URL. Returns null if the URL
     * is unreachable, 0-byte, or unsupported. Reads from `s3_new` disk for
     * legacy bucket URLs, HTTP otherwise.
     */
    private function fetchSourceBytes(string $url, ?Command $cmd): ?string
    {
        foreach (self::OLD_BUCKET_HOSTS as $marker) {
            if (str_contains($url, $marker)) {
                // Plain HTTP GET of the full legacy URL FIRST. Legacy URLs often
                // contain a double slash (members//listings) that Flysystem
                // normalizes away, so the s3_new disk key no longer matches and
                // size()/get() throw — which previously dropped the URL and
                // mass-blanked listings. Public GET still works.
                try {
                    $resp = Http::timeout(30)->retry(2, 1000)->get($url);
                    if ($resp->ok()) {
                        $body = $resp->body();
                        if (strlen($body) > 0) {
                            return $body;
                        }
                    }
                } catch (Throwable $e) {
                    // fall through to the s3_new disk-by-key path
                }

                $key = $this->extractOldKey($url, $marker);
                if ($key === null) {
                    $this->say($cmd, "  skip s3 url={$url} (could not derive key)");
                    return null;
                }
                try {
                    $size = Storage::disk('s3_new')->size($key);
                } catch (Throwable $e) {
                    $this->say($cmd, "  skip s3 key={$key} (not readable: {$e->getMessage()})");
                    return null;
                }
                if ($size === 0) {
                    $this->say($cmd, "  skip s3 key={$key} (0 bytes)");
                    return null;
                }
                try {
                    $body = Storage::disk('s3_new')->get($key);
                } catch (Throwable $e) {
                    $this->say($cmd, "  skip s3 key={$key} (read failed: {$e->getMessage()})");
                    return null;
                }
                if ($body === null || $body === '') {
                    $this->say($cmd, "  skip s3 key={$key} (empty body)");
                    return null;
                }
                return $body;
            }
        }

        // HTTP path (googleusercontent, random external hosts).
        try {
            $resp = Http::timeout(30)->retry(2, 1000)->get($url);
        } catch (Throwable $e) {
            $this->say($cmd, "  skip http url={$url} (GET failed: {$e->getMessage()})");
            return null;
        }
        if (!$resp->ok()) {
            $this->say($cmd, "  skip http url={$url} (GET {$resp->status()})");
            return null;
        }
        $body = $resp->body();
        if (strlen($body) === 0) {
            $this->say($cmd, "  skip http url={$url} (0 bytes)");
            return null;
        }
        return $body;
    }

    /**
     * Decode → scale down to 1200px wide → re-encode as WebP, stepping
     * quality down from 92 until under TARGET_BYTES or MIN_QUALITY hit.
     */
    private function compressToWebp(string $bytes): string
    {
        $manager = new ImageManager(new Driver());
        $image = $manager->read($bytes)->scaleDown(width: self::SCALE_DOWN_WIDTH);

        $quality = self::INITIAL_QUALITY;
        $encoded = '';
        do {
            $encoded = (string) $image->toWebp($quality);
            if (strlen($encoded) <= self::TARGET_BYTES || $quality <= self::MIN_QUALITY) {
                break;
            }
            $quality -= 4;
        } while (true);

        return $encoded;
    }

    /* ─────────────────────────  Helpers  ────────────────────────────────────── */

    private function applyMap(array $urls, array $map): array
    {
        $out = [];
        foreach ($urls as $url) {
            // If it's already a new-bucket URL it's safe to keep even if
            // it wasn't in the map (e.g. when re-running after a partial
            // earlier run).
            if (array_key_exists($url, $map)) {
                if ($map[$url] !== null) {
                    $out[] = $map[$url];
                }
            } else if ($this->isNewBucketUrl($url)) {
                $out[] = $url;
            }
        }
        return $out;
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

    private function extractOldKey(string $url, string $marker): ?string
    {
        $parts = parse_url($url);
        $path  = $parts['path'] ?? null;
        if (!$path) {
            return null;
        }

        if (str_starts_with(ltrim($path, '/'), 'filipinohomes/')) {
            return ltrim(substr(ltrim($path, '/'), strlen('filipinohomes/')), '/');
        }

        if (isset($parts['host']) && str_starts_with($parts['host'], 'filipinohomes.')) {
            return ltrim($path, '/');
        }

        $idx = strpos($url, $marker);
        if ($idx === false) {
            return null;
        }
        return ltrim(substr($url, $idx + strlen($marker)), '/') ?: null;
    }

    private function newBucketUrl(string $key): string
    {
        return Storage::disk('s3')->url($key);
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($v) => is_string($v) && trim($v) !== ''));
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, fn ($v) => is_string($v) && trim($v) !== ''));
            }
            return [$value];
        }
        return [];
    }

    private function say(?Command $cmd, string $msg): void
    {
        if ($cmd) {
            $cmd->line($msg);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('listings:migrate-photos job failed permanently', [
            'property_id' => $this->propertyId,
            'orphan_ids' => $this->orphanListingIds,
            'error' => $e->getMessage(),
        ]);
    }
}
