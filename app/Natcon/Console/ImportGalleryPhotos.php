<?php

namespace App\Natcon\Console;

use App\Models\GalleryAlbum;
use App\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\FaceRecognitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Register photos ALREADY in the S3 bucket as one album of an event's
 * gallery, so browsing and face search run over them without re-uploading.
 *
 * The gallery successor of the retired natcon:import-album. Built for two
 * jobs: bulk-loading a convention's official shots after the photographer
 * drops them into a folder, and pointing a test event at any existing folder
 * (e.g. FHI_GLOBAL/gallery/...) before the real one exists.
 *
 * Rows created here keep their ORIGINAL s3_key — imports are references, not
 * copies. That is safe with the gallery's lifecycle: a gallery delete is a
 * status flip that never touches S3, so removing an imported photo from the
 * admin can never delete someone else's source file. thumb_url stays NULL
 * (no 640px derivative exists for a reference) and every consumer falls back
 * to image_url.
 */
class ImportGalleryPhotos extends Command
{
    protected $signature = 'natcon:import-gallery
        {slug : Event slug, e.g. natcon-2026 (or the test event)}
        {prefix : S3 folder to import, e.g. FHI_GLOBAL/gallery/fhi-global-dubai-event/web}
        {--album= : Album name to file the photos under (created if missing; defaults to the prefix\'s folder name)}
        {--no-index : only create rows; leave face indexing to the scheduled sweep}';

    protected $description = 'Import existing S3 photos into one album of a NATCON event\'s gallery and index their faces';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function handle(FaceRecognitionService $faces): int
    {
        $event = NatconEvent::where('slug', $this->argument('slug'))->first();
        if (! $event) {
            $this->error("No NATCON event with slug '{$this->argument('slug')}'.");

            return self::FAILURE;
        }

        $prefix = trim((string) $this->argument('prefix'), '/');
        $keys = Storage::disk('s3')->files($prefix);

        $images = array_values(array_filter($keys, fn (string $key) => in_array(
            strtolower(pathinfo($key, PATHINFO_EXTENSION)),
            self::IMAGE_EXTENSIONS,
            true,
        )));

        if ($images === []) {
            $this->error("No images found under s3://{$prefix}/ (folders are not scanned recursively).");

            return self::FAILURE;
        }

        // Every gallery photo lives in an album — same rule as the admin UI.
        $albumName = trim((string) ($this->option('album') ?: basename($prefix)));
        $album = GalleryAlbum::firstOrCreate(
            ['natcon_event_id' => $event->id, 'name' => mb_substr($albumName, 0, 120)],
            ['parent_id' => null],
        );

        $this->info(count($images)." image(s) under {$prefix}/ — importing into '{$event->slug}' album \"{$album->name}\".");

        $baseUrl = rtrim((string) config('filesystems.disks.s3.url'), '/');
        $existing = GalleryPhoto::where('natcon_event_id', $event->id)
            ->whereIn('s3_key', $images)
            ->pluck('s3_key')
            ->all();
        // Appended past the current end, like GalleryService::store, so an
        // import never jumps a hand-ordered grid.
        $sort = (int) GalleryPhoto::where('natcon_event_id', $event->id)->max('sort_order');

        $created = 0;
        foreach ($images as $key) {
            if (in_array($key, $existing, true)) {
                continue; // re-running the command must not duplicate rows
            }

            $photo = new GalleryPhoto([
                'natcon_event_id' => $event->id,
                'album_id' => $album->id,
                'image_url' => $baseUrl.'/'.$key,
                'thumb_url' => null,
                's3_key' => $key,
                'status' => GalleryPhoto::STATUS_ACTIVE,
                'sort_order' => ++$sort,
            ]);
            $photo->auditSource = 'natcon_import_gallery';
            $photo->save();
            $created++;
        }

        $skipped = count($images) - $created;
        $this->info("Created {$created} row(s)".($skipped ? ", skipped {$skipped} already imported" : '').'.');

        if ($this->option('no-index')) {
            $this->line('Indexing left to natcon:index-gallery-faces.');

            return self::SUCCESS;
        }

        $pending = GalleryPhoto::where('natcon_event_id', $event->id)
            ->where('status', '!=', GalleryPhoto::STATUS_DELETED)
            ->whereNull('faces_indexed_at')
            ->with('event')
            ->orderBy('id')
            ->get();

        $bar = $this->output->createProgressBar($pending->count());
        $indexed = 0;
        $failed = 0;

        foreach ($pending as $photo) {
            try {
                $faces->indexPhoto($photo);
                $indexed++;
            } catch (\Throwable $e) {
                $photo->forceFill(['index_error' => mb_substr($e->getMessage(), 0, 512)])->save();
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Indexed {$indexed} photo(s)".($failed ? ", {$failed} failed (see index_error)" : '').'.');

        return self::SUCCESS;
    }
}
