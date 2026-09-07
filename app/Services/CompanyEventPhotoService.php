<?php

namespace App\Services;

use App\Models\CompanyEvent;
use App\Models\CompanyEventPhoto;
use App\Natcon\Services\PhotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Stores a company-event picture: encode (main + 640px thumb), upload to S3,
 * append a row. The gallery pipeline's shape (GalleryService) minus what
 * events don't need — no face indexing, no albums, no upload invites.
 *
 * PhotoService::inspect() is REUSED: the decompression-bomb gate is a guard,
 * not a tuning knob — there is exactly one definition of "safe to decode".
 */
final class CompanyEventPhotoService
{
    /** Same archive-grade budget as the public gallery. */
    private const MAX_DIMENSION = 4096;

    private const QUALITY = 88;

    private const THUMB_WIDTH = 640;

    private const THUMB_QUALITY = 78;

    private const S3_PREFIX = 'filipinohomes-new/events';

    public function __construct(private PhotoService $photos) {}

    public function store(CompanyEvent $event, UploadedFile $file, ?int $userId): CompanyEventPhoto
    {
        // Before Intervention touches the file — the gate runs on
        // getimagesize(), not the decoder (see PhotoService's docblock).
        $check = $this->photos->inspect($file);
        if (! $check['ok']) {
            throw new RuntimeException($check['reason'] ?? 'Unsupported image.');
        }

        $manager = new ImageManager(new Driver);
        $image = $manager->read($file->getRealPath())
            ->scaleDown(width: self::MAX_DIMENSION, height: self::MAX_DIMENSION);
        $encoded = (string) $image->toJpeg(self::QUALITY);

        // Thumb from a FRESH read of the encoded bytes — Intervention v3
        // mutates in place, so re-scaling $image would compound and the
        // recorded width/height would describe a different file.
        $thumb = $manager->read($encoded)->scaleDown(width: self::THUMB_WIDTH);
        $thumbEncoded = (string) $thumb->toJpeg(self::THUMB_QUALITY);

        $uuid = (string) Str::uuid();
        $key = self::S3_PREFIX.'/'.$uuid.'.jpg';
        $thumbKey = self::S3_PREFIX.'/'.$uuid.'-640.jpg';

        Storage::disk('s3')->put($key, $encoded, 'public');
        Storage::disk('s3')->put($thumbKey, $thumbEncoded, 'public');

        $base = rtrim((string) config('filesystems.disks.s3.url'), '/');

        $photo = new CompanyEventPhoto([
            'company_event_id' => $event->id,
            'image_url' => $base.'/'.$key,
            'thumb_url' => $base.'/'.$thumbKey,
            's3_key' => $key,
            'width' => $image->width(),
            'height' => $image->height(),
            'byte_size' => strlen($encoded),
            // Append to the end; max over ALL statuses so restoring a hidden
            // row can never collide with something uploaded since.
            'sort_order' => (int) CompanyEventPhoto::where('company_event_id', $event->id)->max('sort_order') + 1,
            'status' => CompanyEventPhoto::STATUS_ACTIVE,
            'created_by' => $userId,
        ]);
        $photo->auditSource = 'admin_company_events';
        $photo->save();

        return $photo;
    }
}
