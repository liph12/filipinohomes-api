<?php

namespace App\Natcon\Services;

use App\Natcon\Models\GalleryPhoto;
use App\Natcon\Models\NatconEvent;
use Illuminate\Http\UploadedFile;
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
 * The ENCODER budget is deliberately this service's own. natcon.gallery, not
 * natcon.photo: awardee photos are print-grade (2000px / 600KB) where these
 * are web-grade (1920px / 400KB, plus a 640px thumb for the grid). Coupling
 * the two would let a print-driven retune silently fatten an indexable page —
 * the same argument PhotoService makes for copying encodeUnderTarget instead
 * of inheriting it from the listing pipeline.
 */
final class GalleryService
{
    public function __construct(
        private PhotoService $photos,
    ) {
    }

    /**
     * Encode, upload (main + thumb), and append a row to the event's gallery.
     */
    public function store(
        NatconEvent $event,
        UploadedFile $file,
        ?int $userId,
        ?string $caption = null,
        ?string $album = null,
    ): GalleryPhoto {
        // Before Intervention touches the file — see PhotoService's docblock on
        // why the gate runs on getimagesize(), not the decoder.
        $check = $this->photos->inspect($file);
        if (! $check['ok']) {
            throw new RuntimeException($check['reason'] ?? 'Unsupported image.');
        }

        $manager = new ImageManager(new Driver());
        $image   = $manager->read($file->getRealPath());

        // Both dimensions capped — gallery shots come in portrait as well as
        // landscape, and scaleDown never upscales a smaller original.
        $maxWidth = (int) config('natcon.gallery.max_width', 1920);
        $image    = $image->scaleDown(width: $maxWidth, height: $maxWidth);

        $encoded = $this->encodeUnderTarget(
            fn (int $q) => (string) $image->toJpeg($q),
            (int) config('natcon.gallery.target_bytes', 400 * 1024),
            50,
            88,
        );

        // Thumb from a FRESH read of the encoded bytes, not from $image:
        // Intervention v3 mutates in place, so a second scaleDown on the same
        // instance would compound with the first and the recorded width/height
        // above would describe a different file.
        $thumb = $manager->read($encoded)
            ->scaleDown(width: (int) config('natcon.gallery.thumb_width', 640));
        $thumbEncoded = (string) $thumb->toJpeg((int) config('natcon.gallery.thumb_quality', 78));

        // Derived from the event's slug — see NatconEvent::s3Prefix() for why a
        // config default is how next year's photos land in this year's folder.
        $prefix   = trim($event->s3Prefix('gallery'), '/');
        $uuid     = (string) Str::uuid();
        $key      = $prefix . '/' . $uuid . '.jpg';
        $thumbKey = $prefix . '/' . $uuid . '-640.jpg';

        Storage::disk('s3')->put($key, $encoded, 'public');
        Storage::disk('s3')->put($thumbKey, $thumbEncoded, 'public');

        $base = rtrim((string) config('filesystems.disks.s3.url'), '/');

        $photo = new GalleryPhoto([
            'natcon_event_id' => $event->id,
            'image_url'       => $base . '/' . $key,
            'thumb_url'       => $base . '/' . $thumbKey,
            's3_key'          => $key,
            'caption'         => $caption,
            'album'           => $album,
            'width'           => $image->width(),
            'height'          => $image->height(),
            'byte_size'       => strlen($encoded),
            'status'          => GalleryPhoto::STATUS_ACTIVE,
            // Append to the end, so a fresh upload never jumps a hand-ordered
            // grid. Max over ALL statuses — a hidden row keeps its slot, and
            // un-hiding it must not collide with whatever was uploaded since.
            'sort_order'      => (int) GalleryPhoto::where('natcon_event_id', $event->id)->max('sort_order') + 1,
            'created_by'      => $userId,
        ]);
        $photo->auditSource = 'admin_natcon_gallery';
        $photo->save();

        return $photo;
    }

    /**
     * Binary-search the JPEG quality that lands just under the byte target.
     * Lifted from PhotoService::encodeUnderTarget (itself lifted from
     * ImageUploadController) — same algorithm, different budget, and
     * deliberately copied rather than shared so a retune of the print pipeline
     * can't silently reshape the public gallery.
     */
    private function encodeUnderTarget(callable $encode, int $targetBytes, int $minQ, int $maxQ): string
    {
        $best = $encode($maxQ);
        if (strlen($best) <= $targetBytes) {
            return $best;
        }

        $lo   = $minQ;
        $hi   = $maxQ - 1;
        $best = $encode($minQ);

        while ($lo <= $hi) {
            $mid       = intdiv($lo + $hi, 2);
            $candidate = $encode($mid);

            if (strlen($candidate) <= $targetBytes) {
                $best = $candidate;
                $lo   = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best;
    }
}
