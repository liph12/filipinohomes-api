<?php

namespace App\Natcon\Console;

use App\Natcon\Models\AlbumPhoto;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\FaceRecognitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Register photos ALREADY in the S3 bucket as an event's album, so face
 * search can run over them without re-uploading anything.
 *
 * Built for two jobs: bulk-loading a convention's official shots after the
 * photographer drops them into a folder, and pointing a test event at any
 * existing album (e.g. FHI_GLOBAL/gallery/...) before the real one exists.
 *
 * Rows created here keep their ORIGINAL s3_key — imports are references, not
 * copies. AlbumService::delete() only removes S3 objects living inside the
 * event's own gallery folder, so deleting an imported photo from the admin
 * drops the row and its face vectors but leaves the source file untouched.
 */
class ImportAlbumPhotos extends Command
{
    protected $signature = 'natcon:import-album
        {slug : Event slug, e.g. natcon-2026 or SAMPLE}
        {prefix : S3 folder to import, e.g. FHI_GLOBAL/gallery/fhi-global-dubai-event/web}
        {--no-index : only create rows; leave indexing to the scheduled sweep}';

    protected $description = 'Import existing S3 photos into a NATCON event album and index their faces';

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

        $this->info(count($images)." image(s) under {$prefix}/ — importing into '{$event->slug}'.");

        $baseUrl = rtrim((string) config('filesystems.disks.s3.url'), '/');
        $existing = AlbumPhoto::whereIn('s3_key', $images)->pluck('s3_key')->all();

        $created = 0;
        foreach ($images as $key) {
            if (in_array($key, $existing, true)) {
                continue; // re-running the command must not duplicate rows
            }

            AlbumPhoto::create([
                'natcon_event_id' => $event->id,
                's3_key' => $key,
                'photo_url' => $baseUrl.'/'.$key,
                'original_filename' => mb_substr(basename($key), 0, 255),
            ]);
            $created++;
        }

        $skipped = count($images) - $created;
        $this->info("Created {$created} row(s)".($skipped ? ", skipped {$skipped} already imported" : '').'.');

        if ($this->option('no-index')) {
            $this->line('Indexing left to natcon:index-album-faces.');

            return self::SUCCESS;
        }

        $pending = AlbumPhoto::where('natcon_event_id', $event->id)
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
