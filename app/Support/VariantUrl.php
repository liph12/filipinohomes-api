<?php

namespace App\Support;

/**
 * Single source of truth for responsive image-variant URLs.
 *
 * Property/listing photos are stored as full S3 URLs on the live bucket
 * (`filipinohomes123`). For each original `<dir>/<uuid>.webp` we generate
 * sibling width-variants `<dir>/<uuid>-{w}w.webp` (see ImageVariantService +
 * GenerateImageVariantsJob). This class is the ONLY place that knows the
 * naming convention, so the backfill (which WRITES the variants) and the API
 * Resources (which EMIT the srcset) can never drift.
 *
 * Pure string arithmetic — no S3 / DB calls — so the Resources can call it
 * per-photo with zero I/O. URLs not on our bucket return null (external /
 * legacy hosts simply get no srcset and fall back to the original).
 */
class VariantUrl
{
    /** Widths we generate as separate files. The original (~1200px) serves as the top descriptor. */
    public const WIDTHS = [320, 480, 640, 800];

    /** Nominal descriptor for the original file (uploads scaleDown to 1200px). */
    public const ORIGINAL_WIDTH = 1200;

    /** Hosts that serve our live bucket (mirrors MigrateListingPhotosJob::NEW_BUCKET_HOSTS). */
    private const HOSTS = [
        'filipinohomes123.s3.ap-southeast-1.amazonaws.com',
        'filipinohomes123.s3.amazonaws.com',
    ];

    /** The S3 object key for a full URL on our bucket, or null if it's not ours. */
    public static function keyFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['host']) || empty($parts['path'])) {
            return null;
        }
        if (!in_array($parts['host'], self::HOSTS, true)) {
            return null;
        }
        $key = ltrim($parts['path'], '/');
        return $key !== '' ? $key : null;
    }

    /** Variant object key for a given original key + width (always .webp). */
    public static function variantKey(string $originalKey, int $width): string
    {
        $key = ltrim($originalKey, '/');
        $dot = strrpos($key, '.');
        $base = $dot === false ? $key : substr($key, 0, $dot);
        return "{$base}-{$width}w.webp";
    }

    /** Variant URL on the SAME scheme+host as the original, or null if off-bucket. */
    public static function variantUrl(string $url, int $width): ?string
    {
        $key = self::keyFromUrl($url);
        if ($key === null) {
            return null;
        }
        $parts = parse_url($url);
        return $parts['scheme'] . '://' . $parts['host'] . '/' . self::variantKey($key, $width);
    }

    /**
     * Full `srcset` string for an original URL, or null when the URL is not on
     * our bucket. Emits the generated widths plus the original as the top
     * (~1200w) descriptor. Pair with appropriate `sizes` on the frontend.
     */
    public static function srcset(string $url): ?string
    {
        if (self::keyFromUrl($url) === null) {
            return null;
        }
        $entries = [];
        foreach (self::WIDTHS as $w) {
            $vu = self::variantUrl($url, $w);
            if ($vu !== null) {
                $entries[] = "{$vu} {$w}w";
            }
        }
        $entries[] = "{$url} " . self::ORIGINAL_WIDTH . 'w';
        return implode(', ', $entries);
    }
}
