<?php

namespace App\Natcon\Services;

use App\Natcon\Models\PhotoSubmission;
use App\Natcon\Models\Recipient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Stores an awardee's replacement photo.
 *
 * ─── Why this is NOT ImageUploadController::handleS3Upload ───────────────────
 *
 * That method is tuned for listing thumbnails behind auth:sanctum. Reusing it
 * here would be wrong on four counts, each of which matters:
 *
 *   1. It takes the S3 folder straight from the request body. On an endpoint
 *      anonymous awardees can reach, that is attacker-controlled key prefixing.
 *      Here the key is entirely server-side.
 *   2. It targets 50KB WebP. Fifty kilobytes visibly destroys a face, and these
 *      photos go onto printed backdrops and ID cards.
 *   3. WebP is a poor handoff format for an events/print workflow — plenty of
 *      lanyard and badge software still can't open it. We store JPEG.
 *   4. It fans out to ImageVariantService for a responsive srcset nobody will
 *      build from a headshot — five extra S3 writes per upload, on a public
 *      endpoint.
 *
 * It also decodes straight into GD with a 50MB ceiling. A flat-colour
 * 12000x12000 PNG is ~100KB on the wire and decodes to ~576MB, which OOMs a
 * PHP-FPM worker; behind auth that's a nuisance, on a public route it's a way to
 * take api2 down and with it every login OTP. Hence the getimagesize() gate below,
 * which runs BEFORE Intervention touches the file.
 */
final class PhotoService
{
    /** @return array{ok:bool, reason:?string, width:?int, height:?int} */
    public function inspect(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            return ['ok' => false, 'reason' => 'That file does not look like an image.', 'width' => null, 'height' => null];
        }

        [$width, $height] = $info;

        $maxDim = (int) config('natcon.photo.max_dimension', 8000);
        $maxMp  = (int) config('natcon.photo.max_megapixels', 40);

        if ($width > $maxDim || $height > $maxDim) {
            return [
                'ok'     => false,
                'reason' => "Image is too large ({$width}x{$height}). Please use one under {$maxDim}px on each side.",
                'width'  => $width,
                'height' => $height,
            ];
        }

        // Decompression-bomb gate. Pixel count, not file size — the whole trick is
        // that a bomb is tiny on the wire and enormous in memory.
        if (($width * $height) > ($maxMp * 1_000_000)) {
            return [
                'ok'     => false,
                'reason' => "Image resolution is too high ({$width}x{$height}). Please use one under {$maxMp} megapixels.",
                'width'  => $width,
                'height' => $height,
            ];
        }

        return ['ok' => true, 'reason' => null, 'width' => $width, 'height' => $height];
    }

    /**
     * Encode, upload, and make it the recipient's active photo.
     *
     * The previous active submission is flipped to `superseded` rather than
     * deleted — someone uploads the wrong photo, uploads again, then wants the
     * first one back, and that conversation happens at least once per campaign.
     */
    public function store(
        Recipient $recipient,
        UploadedFile $file,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PhotoSubmission {
        $check = $this->inspect($file);
        if (! $check['ok']) {
            throw new RuntimeException($check['reason'] ?? 'Unsupported image.');
        }

        $manager = new ImageManager(new Driver());
        $image   = $manager->read($file->getRealPath());

        // Strips EXIF as a side effect of re-encoding, which also removes GPS
        // coordinates from a phone photo — not the point, but worth having.
        $image = $image->scaleDown(width: (int) config('natcon.photo.max_width', 2000));

        $encoded = $this->encodeUnderTarget(
            fn (int $q) => (string) $image->toJpeg($q),
            (int) config('natcon.photo.target_bytes', 600 * 1024),
            40,
            92,
        );

        // Derived from the event's slug, not a config default. A default would
        // silently drop next year's photos into this year's folder — which is
        // exactly what NATCON_S3_PREFIX did before, undocumented in .env.example.
        $prefix = trim($recipient->event?->s3Prefix() ?: (string) config('natcon.photo.s3_prefix'), '/');
        $key    = $prefix . '/' . Str::uuid() . '.jpg';

        Storage::disk('s3')->put($key, $encoded, 'public');

        $url = rtrim((string) config('filesystems.disks.s3.url'), '/') . '/' . $key;

        return DB::transaction(function () use ($recipient, $key, $url, $encoded, $file, $image, $ip, $userAgent) {
            PhotoSubmission::where('natcon_recipient_id', $recipient->id)
                ->where('status', PhotoSubmission::STATUS_ACTIVE)
                ->update(['status' => PhotoSubmission::STATUS_SUPERSEDED]);

            $submission = PhotoSubmission::create([
                'natcon_recipient_id' => $recipient->id,
                'natcon_event_id'     => $recipient->natcon_event_id,
                'photo_url'           => $url,
                's3_key'              => $key,
                'original_filename'   => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'mime_type'           => 'image/jpeg',
                'byte_size'           => strlen($encoded),
                'width'               => $image->width(),
                'height'              => $image->height(),
                'status'              => PhotoSubmission::STATUS_ACTIVE,
                'review_status'       => PhotoSubmission::REVIEW_PENDING,
                'uploaded_ip'         => $ip,
                'uploaded_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            ]);

            $recipient->forceFill([
                'current_photo_url' => $url,
                'photo_uploaded_at' => Carbon::now(),
                'status'            => Recipient::STATUS_PHOTO_UPLOADED,
            ])->save();

            return $submission;
        });
    }

    /**
     * Binary-search the JPEG quality that lands just under the byte target.
     * Lifted from ImageUploadController::encodeUnderTarget — same algorithm,
     * different budget, and deliberately copied rather than inherited so a future
     * change to the listing pipeline can't silently reshape awardee photos.
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
