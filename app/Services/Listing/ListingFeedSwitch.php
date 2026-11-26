<?php

namespace App\Services\Listing;

use App\Models\Listing;
use App\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The live switches for how listings are ordered and how their prices are judged. They are rows of the
 * `settings` table, so changing one is a database change, not a deploy, and it reaches every host that
 * serves the API within half a minute. Nothing here needs a migration.
 *
 *   listings.feed.order           legacy | groups     how public result pages are ordered
 *   listings.price_guard          on | off            judge prices (price_status, price filters)
 *   listings.price_validation     off | log | enforce what the write paths do with an implausible price
 *   listings.rotation.picker      id | fair           which listings the hourly rotation stamps
 *   listings.rotation.*           numbers             the picker's dials (see config/listings.php)
 *   listings.sitemap_lastmod      on | off            the listing sitemaps carry the real last-change date
 *   listings.price_ok             JSON {id: price}    prices their agent confirmed as right
 *
 * Every default is the behaviour from before the switch existed (config/listings.php). A settings table
 * that cannot be read answers the same defaults: failing safe means failing to "off".
 */
final class ListingFeedSwitch
{
    public const ORDER = 'listings.feed.order';

    public const GUARD = 'listings.price_guard';

    public const VALIDATION = 'listings.price_validation';

    public const PICKER = 'listings.rotation.picker';

    public const LASTMOD = 'listings.sitemap_lastmod';

    public const APPROVED = 'listings.price_ok';

    public const ORDER_LEGACY = 'legacy';

    public const ORDER_GROUPS = 'groups';

    /** How long one process keeps what it read, so a long-running worker still sees a change. */
    private const MEMO_SECONDS = 30;

    /** @var array<string, string>|null */
    private static ?array $memo = null;

    private static float $memoAt = 0.0;

    /**
     * The confirmed prices, decoded once per read of the memo above (the resource asks for them once per listing).
     *
     * @var array<int, float>|null
     */
    private static ?array $approvedMemo = null;

    public static function order(): string
    {
        $value = self::read(self::ORDER, (string) config('listings.feed_order', self::ORDER_LEGACY));

        return $value === self::ORDER_GROUPS ? self::ORDER_GROUPS : self::ORDER_LEGACY;
    }

    public static function priceGuard(): bool
    {
        $value = strtolower(self::read(self::GUARD, config('listings.price_guard') ? 'on' : 'off'));

        return in_array($value, ['on', '1', 'true'], true);
    }

    /** 'off' | 'log' | 'enforce' */
    public static function priceValidation(): string
    {
        $value = strtolower(self::read(self::VALIDATION, (string) config('listings.price_validation', 'log')));

        return in_array($value, ['off', 'log', 'enforce'], true) ? $value : 'log';
    }

    /** 'id' | 'fair' */
    public static function rotationPicker(): string
    {
        $value = strtolower(self::read(self::PICKER, (string) config('listings.rotation_picker', 'id')));

        return $value === 'fair' ? 'fair' : 'id';
    }

    /** Do the listing sitemaps carry the real last-change date (ListingDates) as `lastmod`? */
    public static function sitemapLastmod(): bool
    {
        $value = strtolower(self::read(self::LASTMOD, config('listings.sitemap_lastmod') ? 'on' : 'off'));

        return in_array($value, ['on', '1', 'true'], true);
    }

    /** One of the picker's numbers (batch, incomplete_every, heldback_every, agent_idle_days). */
    public static function dial(string $name): int
    {
        $default = (int) config("listings.rotation.{$name}", 1);
        $value = (int) self::read("listings.rotation.{$name}", (string) $default);

        return $value >= 1 ? $value : $default;
    }

    /**
     * The prices their agents confirmed as right: listing id => the confirmed price. A confirmation
     * is for that exact figure; once the price changes it no longer counts.
     *
     * @return array<int, float>
     */
    public static function approvedPrices(): array
    {
        $all = self::all(); // may refresh the memo, which also drops the decoded copy

        return self::$approvedMemo ??= self::decodeApproved($all[self::APPROVED] ?? '{}');
    }

    /**
     * Record that an agent confirmed this price as right. False when the list is full (config
     * listings.max_approved): it is one settings value that every price-judging query carries, so it stays small.
     */
    public static function approve(int $listingId, float $price): bool
    {
        return self::changeApproved(function (array $all) use ($listingId, $price): ?array {
            $all = self::stillCounting($all);
            if (! isset($all[$listingId]) && count($all) >= self::maxApproved()) {
                return null;
            }
            $all[$listingId] = $price;

            return $all;
        });
    }

    public static function revoke(int $listingId): void
    {
        self::changeApproved(function (array $all) use ($listingId): ?array {
            if (! isset($all[$listingId])) {
                return null; // nothing to take out, nothing to write
            }
            unset($all[$listingId]);

            return $all;
        });
    }

    public static function set(string $key, string $value): void
    {
        self::write($key, $value);
    }

    /** Forget what this process read (tests, and right after a change). */
    public static function flush(): void
    {
        self::$memo = null;
        self::$memoAt = 0.0;
        self::$approvedMemo = null;
    }

    private static function read(string $key, string $default): string
    {
        $all = self::all();

        return array_key_exists($key, $all) && $all[$key] !== '' ? $all[$key] : $default;
    }

    private static function write(string $key, string $value): void
    {
        Setting::set($key, $value);
        self::flush();
    }

    /**
     * Change the list of confirmed prices. It is one settings value, so two agents confirming at once must not
     * lose each other's entry: the row is locked, read FRESH (never through this process's memo, which can be
     * half a minute old) and rewritten in one transaction. The very first write has no row to lock, so an insert
     * that loses the race is run once more and then finds the row.
     *
     * @param  callable(array<int, float>): (array<int, float>|null)  $change  the new list, or null to write nothing
     * @return bool whether a list was written
     */
    private static function changeApproved(callable $change): bool
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $written = DB::transaction(function () use ($change): bool {
                    $row = Setting::query()->where('key', self::APPROVED)->lockForUpdate()->first();
                    $next = $change(self::decodeApproved((string) ($row?->value ?? '{}')));
                    if ($next === null) {
                        return false;
                    }

                    $json = (string) json_encode((object) $next);
                    if ($row) {
                        $row->update(['value' => $json]);
                    } else {
                        Setting::create(['key' => self::APPROVED, 'value' => $json]);
                    }

                    return true;
                });
                self::flush();

                return $written;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Drop the confirmations that no longer mean anything: the listing is gone, or its price is not the confirmed
     * figure any more. They can never count again, they only take room.
     *
     * @param  array<int, float>  $all
     * @return array<int, float>
     */
    private static function stillCounting(array $all): array
    {
        if ($all === []) {
            return [];
        }

        $prices = Listing::query()->whereIn('id', array_keys($all))->pluck('price', 'id');

        return array_filter(
            $all,
            fn (float $price, int $id) => $prices->has($id) && abs((float) $prices[$id] - $price) < 0.005,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return array<int, float> */
    private static function decodeApproved(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $id => $price) {
            if (is_numeric($id) && is_numeric($price)) {
                $out[(int) $id] = (float) $price;
            }
        }

        return $out;
    }

    private static function maxApproved(): int
    {
        return max(1, (int) config('listings.max_approved', 1000));
    }

    /** @return array<string, string> every `listings.*` setting, in one query */
    private static function all(): array
    {
        if (self::$memo !== null && (microtime(true) - self::$memoAt) < self::MEMO_SECONDS) {
            return self::$memo;
        }

        try {
            $rows = Setting::query()->where('key', 'like', 'listings.%')->pluck('value', 'key')->all();
        } catch (\Throwable) {
            $rows = []; // no settings table (a test, a half-migrated host): every switch stays at its default
        }

        self::$memo = array_map(fn ($v) => (string) $v, $rows);
        self::$memoAt = microtime(true);
        self::$approvedMemo = null;

        return self::$memo;
    }
}
