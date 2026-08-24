<?php

namespace App\Natcon\Services;

use App\Natcon\Models\GalleryAlbum;
use App\Natcon\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Stores an event photo for the public landing page's gallery.
 *
 * ─── What is shared with PhotoService and what deliberately is not ───────────
 *
 * inspect() is REUSED (injected): the decompression-bomb gate is a guard, not
 * a tuning knob — the same 100KB PNG that OOMs a worker decoding an awardee
 * headshot OOMs it decoding a gallery shot, so there is exactly one definition
 * of "safe to decode".
 *
 * The ENCODER budget is deliberately this service's own (natcon.gallery, not
 * natcon.photo): archive grade since 2026-08 by the owner's call — 4096px,
 * fixed quality 88, no byte target. These photos are face-indexed, and a byte
 * budget crushed exactly the small background faces and fine detail that made
 * a shot worth publishing. The public grid still renders the 640px thumb —
 * only the lightbox pays for the full-size file.
 */
final class GalleryService
{
    public function __construct(
        private PhotoService $photos,
        private FaceRecognitionService $faces,
    ) {}

    /**
     * Encode, upload (main + thumb), and append a row to the event's gallery.
     */
    public function store(
        NatconEvent $event,
        UploadedFile $file,
        ?int $userId,
        ?string $caption = null,
        ?GalleryAlbum $album = null,
    ): GalleryPhoto {
        // Before Intervention touches the file — see PhotoService's docblock on
        // why the gate runs on getimagesize(), not the decoder.
        $check = $this->photos->inspect($file);
        if (! $check['ok']) {
            throw new RuntimeException($check['reason'] ?? 'Unsupported image.');
        }

        $manager = new ImageManager(new Driver);
        $image = $manager->read($file->getRealPath());

        // Both dimensions capped — gallery shots come in portrait as well as
        // landscape, and scaleDown never upscales a smaller original. One
        // fixed encode, album-style: whatever size q88 produces is the size
        // we serve (grids use the thumb; only the lightbox loads this file).
        $maxDim = (int) config('natcon.gallery.max_dimension', 4096);
        $image = $image->scaleDown(width: $maxDim, height: $maxDim);

        $encoded = (string) $image->toJpeg((int) config('natcon.gallery.quality', 88));

        // Thumb from a FRESH read of the encoded bytes, not from $image:
        // Intervention v3 mutates in place, so a second scaleDown on the same
        // instance would compound with the first and the recorded width/height
        // above would describe a different file.
        $thumb = $manager->read($encoded)
            ->scaleDown(width: (int) config('natcon.gallery.thumb_width', 640));
        $thumbEncoded = (string) $thumb->toJpeg((int) config('natcon.gallery.thumb_quality', 78));

        // Derived from the event's slug — see NatconEvent::s3Prefix() for why a
        // config default is how next year's photos land in this year's folder.
        $prefix = trim($event->s3Prefix('gallery'), '/');
        $uuid = (string) Str::uuid();
        $key = $prefix.'/'.$uuid.'.jpg';
        $thumbKey = $prefix.'/'.$uuid.'-640.jpg';

        Storage::disk('s3')->put($key, $encoded, 'public');
        Storage::disk('s3')->put($thumbKey, $thumbEncoded, 'public');

        $base = rtrim((string) config('filesystems.disks.s3.url'), '/');

        $photo = new GalleryPhoto([
            'natcon_event_id' => $event->id,
            'album_id' => $album?->id,
            'image_url' => $base.'/'.$key,
            'thumb_url' => $base.'/'.$thumbKey,
            's3_key' => $key,
            'caption' => $caption,
            'width' => $image->width(),
            'height' => $image->height(),
            'byte_size' => strlen($encoded),
            'status' => GalleryPhoto::STATUS_ACTIVE,
            // Append to the end, so a fresh upload never jumps a hand-ordered
            // grid. Max over ALL statuses — a hidden row keeps its slot, and
            // un-hiding it must not collide with whatever was uploaded since.
            'sort_order' => (int) GalleryPhoto::where('natcon_event_id', $event->id)->max('sort_order') + 1,
            'created_by' => $userId,
        ]);
        $photo->auditSource = 'admin_natcon_gallery';
        $photo->save();

        // Index inline — production has no queue worker, so a ShouldQueue job
        // would sit in the jobs table for ever. Best-effort: a Rekognition
        // blip must not fail the upload; the row stays faces_indexed_at NULL
        // and natcon:index-gallery-faces retries it.
        try {
            $photo->setRelation('event', $event);
            $this->faces->indexPhoto($photo);
        } catch (\Throwable $e) {
            Log::warning('natcon gallery face indexing failed', [
                'photo_id' => $photo->id,
                's3_key' => $key,
                'error' => $e->getMessage(),
            ]);
            $photo->forceFill(['index_error' => mb_substr($e->getMessage(), 0, 512)])->save();
        }

        return $photo;
    }
}
