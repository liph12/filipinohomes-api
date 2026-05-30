<?php

namespace App\Console\Commands;

use App\Jobs\MigrateListingPhotosJob;
use App\Models\Listing;
use App\Models\Property;
use Illuminate\Console\Command;

class MigrateListingPhotos extends Command
{
    protected $signature = 'listings:migrate-photos
                            {--dry-run         : Report what would change, no DB writes / no S3 puts}
                            {--chunk=100       : DB chunk size when streaming properties/listings}
                            {--limit=          : Optional cap on properties to dispatch this run (orphan listings are unaffected)}
                            {--reprocess       : Re-run even if photos_migrated_at is set}
                            {--queue           : Dispatch jobs to the queue instead of running inline}
                            {--id=             : Migrate a single LISTING by id (resolves to its property; use --orphan-id for property-less listings)}
                            {--orphan-id=      : Migrate a single property-less listing by id (debug)}';

    protected $description = 'Migrate listing/property photos from the legacy AWS bucket and other external hosts to filipinohomes123. Per-property: locks the property + all its listings and migrates each unique source URL exactly once so featured_photo and properties.photos stay consistent.';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunk     = max(1, (int) $this->option('chunk'));
        $limit     = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $reprocess = (bool) $this->option('reprocess');
        $useQueue  = (bool) $this->option('queue');
        $singleId  = $this->option('id') !== null ? (int) $this->option('id') : null;
        $orphanId  = $this->option('orphan-id') !== null ? (int) $this->option('orphan-id') : null;

        $this->info(sprintf(
            '%sMode: %s | Chunk: %d%s%s',
            $dryRun ? '[DRY RUN] ' : '',
            $useQueue ? 'queue dispatch' : 'inline',
            $chunk,
            $limit !== null ? " | Limit: {$limit}" : '',
            $reprocess ? ' | Reprocess: yes' : '',
        ));

        // ── Single-listing debug paths (always inline) ─────────────────
        if ($singleId !== null) {
            $listing = Listing::find($singleId);
            if (!$listing) {
                $this->error("Listing {$singleId} not found (or soft-deleted).");
                return self::FAILURE;
            }
            if ($listing->property_id === null) {
                $this->info("Listing {$singleId} has no property_id — running orphan path.");
                (new MigrateListingPhotosJob(null, [$singleId], $dryRun))->handleInline($this);
            } else {
                $this->info("Listing {$singleId} → property={$listing->property_id}; processing whole property.");
                (new MigrateListingPhotosJob($listing->property_id, [], $dryRun))->handleInline($this);
            }
            return self::SUCCESS;
        }

        if ($orphanId !== null) {
            (new MigrateListingPhotosJob(null, [$orphanId], $dryRun))->handleInline($this);
            return self::SUCCESS;
        }

        // ── Bulk: properties with at least one listing needing migration ─
        $propertyQuery = Property::query()->select(['id']);
        if (!$reprocess) {
            $propertyQuery->where(function ($q) {
                $q->whereNull('photos_migrated_at')
                  ->orWhereHas('listings', fn ($l) => $l->whereNull('photos_migrated_at'));
            });
        }

        $propertyCount = (clone $propertyQuery)->count();
        $this->info("Properties to process: {$propertyCount}");

        $dispatchedProperties = 0;
        $propertyQuery->orderBy('id')->chunkById($chunk, function ($batch) use (
            $dryRun,
            $useQueue,
            $limit,
            &$dispatchedProperties,
        ) {
            foreach ($batch as $property) {
                if ($limit !== null && $dispatchedProperties >= $limit) {
                    return false;
                }
                if ($useQueue) {
                    MigrateListingPhotosJob::dispatch($property->id, [], $dryRun)
                        ->onQueue('photo-migration');
                } else {
                    (new MigrateListingPhotosJob($property->id, [], $dryRun))->handleInline($this);
                }
                $dispatchedProperties++;
            }
        });

        // ── Orphan listings (no property_id) — batched into jobs of 50 ─
        $orphanQuery = Listing::query()->select(['id'])->whereNull('property_id');
        if (!$reprocess) {
            $orphanQuery->whereNull('photos_migrated_at');
        }
        $orphanCount = (clone $orphanQuery)->count();
        $this->info("Orphan listings (no property): {$orphanCount}");

        $orphanIds = [];
        $dispatchedOrphans = 0;
        $orphanQuery->orderBy('id')->chunkById(50, function ($batch) use (
            $dryRun,
            $useQueue,
            &$orphanIds,
            &$dispatchedOrphans,
        ) {
            foreach ($batch as $listing) {
                $orphanIds[] = $listing->id;
                if (count($orphanIds) >= 50) {
                    $this->dispatchOrphanBatch($orphanIds, $useQueue, $dryRun);
                    $dispatchedOrphans += count($orphanIds);
                    $orphanIds = [];
                }
            }
        });
        if (!empty($orphanIds)) {
            $this->dispatchOrphanBatch($orphanIds, $useQueue, $dryRun);
            $dispatchedOrphans += count($orphanIds);
        }

        $this->info(
            $useQueue
                ? "Dispatched {$dispatchedProperties} property job(s) + {$dispatchedOrphans} orphan listing(s) to queue 'photo-migration'."
                : "Processed {$dispatchedProperties} property job(s) + {$dispatchedOrphans} orphan listing(s) inline."
        );

        return self::SUCCESS;
    }

    /** @param  array<int>  $ids */
    private function dispatchOrphanBatch(array $ids, bool $useQueue, bool $dryRun): void
    {
        if ($useQueue) {
            MigrateListingPhotosJob::dispatch(null, $ids, $dryRun)
                ->onQueue('photo-migration');
        } else {
            (new MigrateListingPhotosJob(null, $ids, $dryRun))->handleInline($this);
        }
    }
}
