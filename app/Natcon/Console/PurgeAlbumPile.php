<?php

namespace App\Natcon\Console;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\FaceRecognitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One-off cleanup for the retired face-search album pile.
 *
 * Deliberately self-contained — the AlbumPhoto model and its literals are
 * gone, so the legacy values live here: uploads landed under natcon/{slug}/
 * on S3 (albumS3Prefix()) and vectors in the fh-gallery-{slug} Rekognition
 * collection (albumCollectionId()). Both are namespaced away from everything
 * the live gallery uses (filipinohomes-new/{slug}/gallery and
 * fh-natcon-gallery-{slug}), so deleting the whole prefix and collection
 * cannot touch current data. Photos registered by the old natcon:import-album
 * lived OUTSIDE the prefix as references to files owned elsewhere — those are
 * correctly left alone.
 *
 * Safe to re-run; every step's goal state is "already gone". Stored face
 * vectors bill monthly, which is why the collections must actually be
 * deleted, not merely stop being searched.
 */
class PurgeAlbumPile extends Command
{
    protected $signature = 'natcon:purge-album-pile {--dry-run : List what would be deleted without deleting}';

    protected $description = 'Delete the retired album pile\'s S3 folders and Rekognition collections (run once after removing the feature)';

    public function handle(FaceRecognitionService $faces): int
    {
        $dry = (bool) $this->option('dry-run');

        if (Schema::hasTable('natcon_album_photos')) {
            $this->warn('natcon_album_photos still exists — run `php artisan migrate` after this to drop it.');
        }

        foreach (NatconEvent::all() as $event) {
            $prefix = 'natcon/'.$event->slug;
            $collection = 'fh-gallery-'.$event->slug;

            $files = Storage::disk('s3')->allFiles($prefix);
            $this->line("{$event->slug}: {$prefix}/ holds ".count($files).' file(s); collection '.$collection);

            if ($dry) {
                continue;
            }

            if ($files !== []) {
                Storage::disk('s3')->delete($files);
                $this->info('  deleted '.count($files).' S3 file(s)');
            }

            try {
                $faces->deleteCollection($collection);
                $this->info('  deleted Rekognition collection (or it was already gone)');
            } catch (\Throwable $e) {
                // Non-fatal: one bad collection must not block the other years.
                $this->error("  collection delete failed: {$e->getMessage()}");
            }
        }

        $this->info($dry ? 'Dry run — nothing deleted.' : 'Album pile purged.');

        return self::SUCCESS;
    }
}
