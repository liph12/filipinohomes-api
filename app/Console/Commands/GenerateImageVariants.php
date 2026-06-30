<?php

namespace App\Console\Commands;

use App\Jobs\GenerateImageVariantsJob;
use App\Models\Listing;
use App\Models\Property;
use Illuminate\Console\Command;

class GenerateImageVariants extends Command
{
    protected $signature = 'images:generate-variants
                            {--dry-run    : Report what would be generated, no S3 puts / no DB writes}
                            {--chunk=100  : DB chunk size when streaming properties/listings}
                            {--limit=     : Optional cap on properties to dispatch this run}
                            {--reprocess  : Re-run even if photos_variants_generated_at is set}
                            {--queue      : Dispatch to the image-variants queue instead of running inline}
                            {--id=        : Single LISTING id (resolves to its property)}
                            {--orphan-id= : Single property-less listing id (debug)}';

    protected $description = 'Generate responsive WebP width-variants on S3 for listing/property photos and flag each record so the API emits srcset. Per-property: each unique source URL is processed once. Idempotent; run throttled with --limit/--chunk.';

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
                (new GenerateImageVariantsJob(null, [$singleId], $dryRun))->handleInline($this);
            } else {
                (new GenerateImageVariantsJob($listing->property_id, [], $dryRun))->handleInline($this);
            }
            return self::SUCCESS;
        }

        if ($orphanId !== null) {
            (new GenerateImageVariantsJob(null, [$orphanId], $dryRun))->handleInline($this);
            return self::SUCCESS;
        }

        // ── Bulk: properties whose property or any listing lacks variants ─
        $propertyQuery = Property::query()->select(['id']);
        if (!$reprocess) {
            $propertyQuery->where(function ($q) {
                $q->whereNull('photos_variants_generated_at')
                  ->orWhereHas('listings', fn ($l) => $l->whereNull('photos_variants_generated_at'));
            });
        }
        $propertyCount = (clone $propertyQuery)->count();
        $this->info("Properties to process: {$propertyCount}");

        $dispatched = 0;
        $propertyQuery->orderBy('id')->chunkById($chunk, function ($batch) use ($dryRun, $useQueue, $limit, &$dispatched) {
            foreach ($batch as $property) {
                if ($limit !== null && $dispatched >= $limit) {
                    return false;
                }
                if ($useQueue) {
                    GenerateImageVariantsJob::dispatch($property->id, [], $dryRun)->onQueue('image-variants');
                } else {
                    (new GenerateImageVariantsJob($property->id, [], $dryRun))->handleInline($this);
                }
                $dispatched++;
            }
        });

        // ── Orphan listings (no property_id) — batched into jobs of 50 ─
        $orphanQuery = Listing::query()->select(['id'])->whereNull('property_id');
        if (!$reprocess) {
            $orphanQuery->whereNull('photos_variants_generated_at');
        }
        $orphanCount = (clone $orphanQuery)->count();
        $this->info("Orphan listings (no property): {$orphanCount}");

        $orphanIds = [];
        $dispatchedOrphans = 0;
        $orphanQuery->orderBy('id')->chunkById(50, function ($batch) use ($dryRun, $useQueue, &$orphanIds, &$dispatchedOrphans) {
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

        $this->info($useQueue
            ? "Dispatched {$dispatched} property job(s) + {$dispatchedOrphans} orphan(s) to queue 'image-variants'."
            : "Processed {$dispatched} property job(s) + {$dispatchedOrphans} orphan(s) inline.");

        return self::SUCCESS;
    }

    /** @param array<int> $ids */
    private function dispatchOrphanBatch(array $ids, bool $useQueue, bool $dryRun): void
    {
        if ($useQueue) {
            GenerateImageVariantsJob::dispatch(null, $ids, $dryRun)->onQueue('image-variants');
        } else {
            (new GenerateImageVariantsJob(null, $ids, $dryRun))->handleInline($this);
        }
    }
}
