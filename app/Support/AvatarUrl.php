<?php

namespace App\Support;

/**
 * Output guard for avatar URLs emitted by the API Resources.
 *
 * Some legacy rows carry a broken concatenation: an S3 base path glued in
 * front of a value that was ALREADY an absolute URL (an upload flow once
 * prefixed `.../members/{id}/photo/` onto whatever the column held, including
 * external `http(s)://...` avatars). Repairing at emission time keeps every
 * endpoint safe without a risky mass data rewrite — the stored value stays
 * as-is and only the JSON is cleaned.
 *
 * Pure string arithmetic — no S3 / DB calls — so Resources can call it
 * per-row with zero I/O (same contract as {@see VariantUrl}).
 */
final class AvatarUrl
{
    /**
     * Repair legacy concatenations: an S3 base glued in front of a value
     * that was already an absolute URL (e.g. .../members/123/photo/http://...).
     * Keeps the LAST embedded http(s) URL; arrays clean element-wise.
     */
    public static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn ($v) => self::clean($v), $value);
        }
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $pos = max((int) strrpos($value, 'http://'), (int) strrpos($value, 'https://'));
        return $pos > 0 ? substr($value, $pos) : $value;
    }
}
