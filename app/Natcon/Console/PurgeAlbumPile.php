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
 * Legacy collections are DISCOVERED via ListCollections (any id starting
 * 'fh-gallery-'), not derived from natcon_events — a collection whose event
 * row was renamed or deleted must still be found and purged. ListCollections
 * is an account-level permission ("Resource": "*"); when the IAM user lacks
 * it, the command says so and falls back to the per-event names.
 *
 * Safe to re-run; every step's goal state is "already gone", and --dry-run is
 * also how you CHECK what is left after a purge (it deletes nothing). Stored
 * face vectors bill monthly, which is why the collections must actually be
 * deleted, not merely stop being searched.
 */
class PurgeAlbumPile extends Command
{
    protected $signature = 'natcon:purge-album-pile {--dry-run : List what remains without deleting anything}';

    protected $description = 'Delete (or with --dry-run, list) the retired album pile\'s S3 folders and Rekognition collections';

    /** The retired pile's collection namespace. The live gallery uses fh-natcon-gallery-. */
    private const LEGACY_COLLECTION_PREFIX = 'fh-gallery-';

    public function handle(FaceRecognitionService $faces): int
    {
        $dry = (bool) $this->option('dry-run');
        $clean = true;

        if (Schema::hasTable('natcon_album_photos')) {
            $this->warn('natcon_album_photos still exists — run `php artisan migrate` after this to drop it.');
            $clean = false;
        }

        // ── S3: the pile's own folder per event ─────────────────────────────
        foreach (NatconEvent::all() as $event) {
            $prefix = 'natcon/'.$event->slug;
            $files = Storage::disk('s3')->allFiles($prefix);

            if ($files === []) {
                $this->line("s3://{$prefix}/ — clean");

                continue;
            }

            $clean = false;
            $this->warn("s3://{$prefix}/ — ".count($files).' file(s) remaining');

            if (! $dry) {
                Storage::disk('s3')->delete($files);
                $this->info('  deleted '.count($files).' S3 file(s)');
            }
        }

        // ── Rekognition: every fh-gallery-* collection, discovered ──────────
        try {
            $legacy = array_values(array_filter(
                $faces->listCollections(),
                fn (string $id) => str_starts_with($id, self::LEGACY_COLLECTION_PREFIX),
            ));
        } catch (\Throwable $e) {
            $this->warn('ListCollections not permitted ('.$e->getMessage().')');
            $this->warn('Falling back to event-derived names — a collection for a deleted/renamed event cannot be found this way.');
            $legacy = NatconEvent::pluck('slug')
                ->map(fn (string $slug) => self::LEGACY_COLLECTION_PREFIX.$slug)
                ->all();
        }

        if ($legacy === []) {
            $this->line('Rekognition — no '.self::LEGACY_COLLECTION_PREFIX.'* collections remain.');
        }

        foreach ($legacy as $collection) {
            $clean = false;
            $this->warn("collection {$collection} — remaining");

            if ($dry) {
                continue;
            }

            try {
                $faces->deleteCollection($collection);
                $this->info('  deleted (or it was already gone)');
            } catch (\Throwable $e) {
                // Non-fatal: one bad collection must not block the rest.
                $this->error("  delete failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(match (true) {
            $dry && $clean => 'Dry run — nothing left to purge.',
            $dry => 'Dry run — the items above remain; run without --dry-run to delete them.',
            default => 'Album pile purged. Re-run with --dry-run to verify everything reads clean.',
        });

        return self::SUCCESS;
    }
}
