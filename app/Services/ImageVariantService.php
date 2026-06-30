<?php

namespace App\Services;

use App\Support\VariantUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Generates responsive WebP width-variants for an already-stored original
 * photo and writes them to S3 as sibling keys (see VariantUrl). Used by:
 *   - the upload paths (new images get variants immediately), and
 *   - GenerateImageVariantsJob (backfill of the existing catalogue).
 *
 * The variant keys it writes are EXACTLY the keys VariantUrl::variantKey()
 * produces, so the API srcset always points at files that exist.
 */
class ImageVariantService
{
    /** WebP quality for resized variants (smaller widths → small files; no binary search needed). */
    private const VARIANT_QUALITY = 80;

    /**
     * Generate + store all width-variants for one original.
     *
     * @param  string  $sourceBytes  Raw bytes of the BEST available source (the
     *                                uploaded original, or the stored original
     *                                re-fetched) — decoded fresh per width so
     *                                widths never compound-scale.
     * @param  string  $originalKey  The original's S3 key (leading slash tolerated).
     * @return int  Number of variant files written.
     */
    public function generateVariants(string $sourceBytes, string $originalKey): int
    {
        if ($sourceBytes === '' || trim($originalKey) === '') {
            return 0;
        }

        $manager = new ImageManager(new Driver());
        $written = 0;

        foreach (VariantUrl::WIDTHS as $width) {
            try {
                // Re-read the source each iteration: Intervention v3 mutates in
                // place, so reusing one instance would scale 320→480→… instead
                // of source→width. scaleDown never upscales (a 600px source asked
                // for 800w yields 600px), so every variant key still gets a real
                // file and the srcset never 404s.
                $image = $manager->read($sourceBytes)->scaleDown(width: $width);
                $webp = (string) $image->toWebp(self::VARIANT_QUALITY);
                unset($image);

                if ($webp === '') {
                    continue;
                }

                $variantKey = VariantUrl::variantKey($originalKey, $width);
                if (Storage::disk('s3')->put($variantKey, $webp, 'public')) {
                    $written++;
                }
            } catch (Throwable $e) {
                // Best-effort: a single bad width must not abort the others or
                // the caller (upload request / backfill job).
                Log::warning('ImageVariantService: variant generation failed', [
                    'key' => $originalKey,
                    'width' => $width,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $written;
    }

    /** True only if EVERY expected variant width exists on S3 for this original. */
    public function allVariantsExist(string $originalKey): bool
    {
        foreach (VariantUrl::WIDTHS as $width) {
            if (!Storage::disk('s3')->exists(VariantUrl::variantKey($originalKey, $width))) {
                return false;
            }
        }
        return true;
    }
}
