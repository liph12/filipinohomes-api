<?php

namespace App\Natcon\Console;

use App\Natcon\Models\GalleryPhoto;
use App\Natcon\Services\FaceRecognitionService;
use Illuminate\Console\Command;

/**
 * Retries gallery photos whose inline Rekognition indexing failed at upload
 * (uploads index inline — production has no queue worker) — and, because the
 * face columns were added after the gallery existed, it IS the backfill for
 * every photo uploaded before them (faces_indexed_at NULL is the whole
 * work-list). Deleted rows are skipped: their bytes stay in S3 as a restore
 * pointer, but they must never become searchable.
 */
class IndexGalleryFaces extends Command
{
    protected $signature = 'natcon:index-gallery-faces {--limit=25}';

    protected $description = 'Index un-indexed NATCON gallery photos into their Rekognition face collection';

    public function handle(FaceRecognitionService $faces): int
    {
        $pending = GalleryPhoto::whereNull('faces_indexed_at')
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->with('event')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing to index.');

            return self::SUCCESS;
        }

        $indexed = 0;
        foreach ($pending as $photo) {
            try {
                $count = $faces->indexPhoto($photo);
                $this->line("#{$photo->id} {$photo->s3_key}: {$count} face(s)");
                $indexed++;
            } catch (\Throwable $e) {
                // Recorded, not fatal: one broken photo must not block the rest
                // of the batch.
                $photo->forceFill(['index_error' => mb_substr($e->getMessage(), 0, 512)])->save();
                $this->warn("#{$photo->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Indexed {$indexed} of {$pending->count()} pending photo(s).");

        return self::SUCCESS;
    }
}
