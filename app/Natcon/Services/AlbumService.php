<?php

namespace App\Natcon\Services;

use App\Natcon\Models\AlbumPhoto;
use App\Natcon\Models\NatconEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Stores face-search ALBUM photos (see AlbumPhoto for the album/gallery split).
 *
 * ─── Why this is NOT GalleryService or PhotoService ──────────────────────────
 *
 * Same skeleton, different budget, for one reason: these photos feed
 * Rekognition. Both other pipelines binary-search JPEG quality down to a byte
 * target (600KB print headshots, 400KB landing gallery); run a wide group shot
 * through either and the faces at the back lose exactly the texture a face
 * embedding is built from, so those people silently drop out of everyone's
 * search results. Here there is deliberately NO byte target and NO minimum:
 * one fixed encode at album.quality (88), scaled only when the source exceeds
 * album.max_dimension. Whatever size that produces is the size we pay for —
 * indexing is billed per image, not per megabyte.
 *
 * The decompression-bomb gate is kept (an admin-only route today is a shared
 * route tomorrow) but with a higher ceiling than headshots: event photographers
 * shoot 45MP bodies, and rejecting the official camera's output would be
 * rejecting the album itself.
 */
final class AlbumService
{
    public function __construct(private FaceRecognitionService $faces) {}

    public function store(NatconEvent $event, UploadedFile $file, ?int $userId = null): AlbumPhoto
    {
        $info = @getimagesize($file->getRealPath());
        if ($info === false) {
            throw new RuntimeException('That file does not look like an image.');
        }

        [$width, $height] = $info;

        $maxDim = (int) config('natcon.album.max_source_dimension', 12000);
        $maxMp = (int) config('natcon.album.max_megapixels', 64);

        if ($width > $maxDim || $height > $maxDim) {
            throw new RuntimeException(
                "Image is too large ({$width}x{$height}). Please use one under {$maxDim}px on each side."
            );
        }

        if (($width * $height) > ($maxMp * 1_000_000)) {
            throw new RuntimeException(
                "Image resolution is too high ({$width}x{$height}). Please use one under {$maxMp} megapixels."
            );
        }

        $manager = new ImageManager(new Driver);
        $image = $manager->read($file->getRealPath());

        // Re-encoding strips EXIF (and with it GPS coordinates) as a side
        // effect. scaleDown never upscales, so most files pass through at
        // their native size — the cap only reins in the truly huge ones while
        // keeping background faces detectable in group shots.
        $limit = (int) config('natcon.album.max_dimension', 4096);
        $image = $image->scaleDown(width: $limit, height: $limit);

        $encoded = (string) $image->toJpeg((int) config('natcon.album.quality', 88));

        // e.g. natcon/natcon-2026/<uuid>.jpg — slug-derived (see albumS3Prefix)
        // and entirely server-side; the request never chooses the folder.
        $key = $event->albumS3Prefix().'/'.Str::uuid().'.jpg';

        Storage::disk('s3')->put($key, $encoded, 'public');

        $url = rtrim((string) config('filesystems.disks.s3.url'), '/').'/'.$key;

        $photo = AlbumPhoto::create([
            'natcon_event_id' => $event->id,
            's3_key' => $key,
            'photo_url' => $url,
            'original_filename' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'byte_size' => strlen($encoded),
            'width' => $image->width(),
            'height' => $image->height(),
            'uploaded_by' => $userId,
        ]);

        // Index inline — production has no queue worker (see the note in
        // config/natcon.php on drain_limit), so a ShouldQueue job would sit in
        // the jobs table for ever. Best-effort: a Rekognition blip must not
        // fail the upload; the row stays faces_indexed_at NULL and the
        // natcon:index-album-faces sweep retries it.
        try {
            $photo->setRelation('event', $event);
            $this->faces->indexPhoto($photo);
        } catch (\Throwable $e) {
            Log::warning('natcon album face indexing failed', [
                'photo_id' => $photo->id,
                's3_key' => $key,
                'error' => $e->getMessage(),
            ]);
            $photo->forceFill(['index_error' => mb_substr($e->getMessage(), 0, 512)])->save();
        }

        return $photo;
    }

    /**
     * Hard delete, unlike awardee headshots: the album is bulk-curated (blinks,
     * duplicates, misfires), and every kept row would otherwise stay searchable.
     * Order matters — vectors first, then the object, then the row — so a
     * failure partway never leaves a searchable face pointing at a photo that
     * is gone.
     *
     * The S3 object is only removed when it lives inside the event's own album
     * folder. Photos registered by natcon:import-album keep their original key
     * elsewhere in the bucket (they are references, not copies) — deleting one
     * from the admin must drop the row and its face vectors, not someone
     * else's source file.
     */
    public function delete(AlbumPhoto $photo): void
    {
        $this->faces->forgetPhoto($photo);

        if (str_starts_with($photo->s3_key, $photo->event->albumS3Prefix().'/')) {
            Storage::disk('s3')->delete($photo->s3_key);
        }

        $photo->delete();
    }
}
