<?php

namespace App\Natcon\Console;

use App\Natcon\Models\GalleryPhoto;
use App\Natcon\Services\FaceRecognitionService;
use Illuminate\Console\Command;

/**
 * Retry sweep for gallery photos whose Rekognition indexing failed at upload
 * time (uploads index inline — production has no queue worker, see
 * config/natcon.php on drain_limit).
 *
 * faces_indexed_at NULL is the whole work-list: a successful index always
 * stamps it, including the zero-faces case, so nothing here can loop on a
 * photo that simply has no faces in it.
 */
class IndexGalleryFaces extends Command
{
    protected $signature = 'natcon:index-gallery-faces {--limit=25}';

    protected $description = 'Index un-indexed NATCON gallery photos into their Rekognition face collection';

    public function handle(FaceRecognitionService $faces): int
    {
        $pending = GalleryPhoto::whereNull('faces_indexed_at')
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
                // of the batch, and the error is visible in the admin grid.
                $photo->forceFill(['index_error' => mb_substr($e->getMessage(), 0, 512)])->save();
                $this->warn("#{$photo->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Indexed {$indexed} of {$pending->count()} pending photo(s).");

        return self::SUCCESS;
    }
}
