<?php

namespace App\Support;

use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FH One's API keys: what is configured (config/fhone.php), which one a
 * caller presented, and how a new one is made.
 *
 * A key is a long random secret. Only its SHA-256 is stored, next to a name
 * and the last day it works. A key this long can't be guessed, so a plain
 * hash is enough; the comparison still goes through hash_equals and visits
 * every entry, so how long it takes says nothing about which key was close.
 */
final class FhOneKeys
{
    /** Easy to spot in a log, a paste or a secret scanner. */
    public const PREFIX = 'fhone_';

    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,39}$/';

    /**
     * The usable entries of FHONE_API_KEYS. A malformed one is skipped, which
     * fails closed: nobody can use a key whose entry is broken.
     *
     * @return list<array{name: string, hash: string, expires: string}>
     */
    public static function configured(): array
    {
        $keys = [];

        foreach (explode(',', (string) config('fhone.keys', '')) as $entry) {
            $parts = explode(':', trim($entry));

            if (count($parts) !== 3) {
                continue;
            }

            [$name, $hash, $expires] = $parts;

            if (preg_match(self::NAME_PATTERN, $name) !== 1
                || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
                || ! self::isDay($expires)) {
                continue;
            }

            $keys[] = ['name' => $name, 'hash' => $hash, 'expires' => $expires];
        }

        return $keys;
    }

    /**
     * The configured key this secret is, or null. `expired` says whether its
     * last valid day has passed (Manila time).
     *
     * @return array{name: string, hash: string, expires: string, expired: bool}|null
     */
    public static function match(string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }

        $hash = hash('sha256', $secret);
        $found = null;

        foreach (self::configured() as $key) {
            if (hash_equals($key['hash'], $hash)) {
                $found ??= $key;
            }
        }

        return $found === null ? null : $found + ['expired' => self::hasExpired($found['expires'])];
    }

    /** Whole days until the key stops working (0 on its last day, negative once expired). */
    public static function daysLeft(string $expires): int
    {
        $today = Carbon::createFromFormat('!Y-m-d', ReportRange::today(), ReportRange::TZ);
        $last = Carbon::createFromFormat('!Y-m-d', $expires, ReportRange::TZ);

        return (int) $today->diffInDays($last, false);
    }

    public static function hasExpired(string $expires): bool
    {
        return ReportRange::today() > $expires;
    }

    public static function generate(): string
    {
        return self::PREFIX.Str::random(48);
    }

    /** The FHONE_API_KEYS entry for a key. */
    public static function entry(string $name, string $secret, string $lastDay): string
    {
        return $name.':'.hash('sha256', $secret).':'.$lastDay;
    }

    public static function isDay(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
