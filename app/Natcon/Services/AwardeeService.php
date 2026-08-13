<?php

namespace App\Natcon\Services;

use App\Natcon\Models\Recipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Leuterio Realty NATCON awardee lookup.
 *
 * Sibling of LrApiService — kept as its own class rather than another method on
 * it because it has a different base URL, a different response envelope, and
 * different failure semantics. (The namespace misspelling is LrApiService's; it's
 * preserved so both live together.)
 *
 * ─── Two traps this class exists to contain ───────────────────────────────────
 *
 * 1. A MISS IS AN HTTP 200. The endpoint answers `{"success":false}` with status
 *    200 for an unknown email — it does NOT 404. So `$response->successful()` is
 *    true for a non-awardee, and any code that branches on the status code alone
 *    will happily write an empty snapshot for every address you feed it.
 *
 *    The `success` flag also appears TWICE: once on the envelope and again on the
 *    nested `awardee` object. Both are checked.
 *
 * 2. "NOTHING CAME BACK" HAS THREE MEANINGS. LrApiService collapses them all to
 *    null, which is fine for a login path but wrong here: this service feeds a
 *    mail campaign, and the caller MUST retry a transient failure while MUST NOT
 *    retry a genuine miss. So we return a discriminated result instead:
 *
 *      found     — real awardee, snapshot is good
 *      not_found — HTTP 200 + success:false, this person isn't on LR's list
 *      error     — 429 / 5xx / timeout / malformed. Retry later.
 *
 *    Getting this wrong is a live failure mode: LR rate-limits to 60 req/min per
 *    IP (verified: `x-ratelimit-limit: 60`), shared across everything api2 sends
 *    them. Import 300 emails naively and requests 61+ get 429 — which, collapsed
 *    to null, reads as "240 of these people aren't awardees".
 */
class AwardeeService
{
    public const FOUND     = 'found';
    public const NOT_FOUND = 'not_found';
    public const ERROR     = 'error';

    private const CACHE_PREFIX = 'natcon:awardee:';

    /**
     * @return array{status:string, awardee:?array, http_status:?int, error:?string}
     */
    public function lookup(string $email, bool $fresh = false): array
    {
        $email = strtolower(trim($email));
        $key   = self::cacheKey($email);

        if (! $fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->fetch($email);

        // Cache::remember() cannot express a per-result TTL, so this is an explicit
        // get/put. Errors are deliberately NOT cached — caching a transient LR
        // outage would freeze it into looking like "not an awardee" for an hour.
        if ($result['status'] === self::FOUND) {
            Cache::put($key, $result, (int) config('natcon.lr.cache_ttl_found', 3600));
        } elseif ($result['status'] === self::NOT_FOUND) {
            Cache::put($key, $result, (int) config('natcon.lr.cache_ttl_not_found', 300));
        }

        return $result;
    }

    /**
     * @return array{status:string, awardee:?array, http_status:?int, error:?string}
     */
    private function fetch(string $email): array
    {
        $base = rtrim((string) config('natcon.lr.base_url'), '/');
        $url  = $base . '/' . urlencode($email);

        try {
            $res = Http::timeout((int) config('natcon.lr.timeout', 15))
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            return $this->error(null, 'transport: ' . $e->getMessage());
        }

        // 429 lands here, and it matters that it does: a throttled request is a
        // retryable error, never a miss.
        if (! $res->successful()) {
            return $this->error($res->status(), 'http ' . $res->status());
        }

        // ⚠️ HTTP 200 does NOT mean "found". See the class docblock.
        if ($res->json('success') !== true) {
            return [
                'status'      => self::NOT_FOUND,
                'awardee'     => null,
                'http_status' => 200,
                'error'       => null,
            ];
        }

        $awardee = $res->json('awardee');

        // The nested object carries its own success flag. A 200 with success:true
        // on the envelope but a malformed body is a bug on their side, not a miss —
        // treat it as retryable rather than silently recording "not an awardee".
        if (! is_array($awardee) || ($awardee['success'] ?? null) !== true) {
            return $this->error(200, 'malformed awardee payload');
        }

        return [
            'status'      => self::FOUND,
            'awardee'     => $awardee,
            'http_status' => 200,
            'error'       => null,
        ];
    }

    private function error(?int $status, string $message): array
    {
        return [
            'status'      => self::ERROR,
            'awardee'     => null,
            'http_status' => $status,
            'error'       => substr($message, 0, 500),
        ];
    }

    /**
     * Look the recipient up and persist the snapshot onto their row.
     *
     * Always writes lr_lookup_status and lr_fetched_at so the admin can see what
     * happened; only overwrites the snapshot fields on a genuine hit, so a later
     * LR outage can never blank out data we already have.
     */
    public function hydrate(Recipient $recipient, bool $fresh = false): Recipient
    {
        $result = $this->lookup($recipient->email, $fresh);

        if ($result['status'] === self::FOUND) {
            $recipient->forceFill($this->mapAwardee($result['awardee']) + [
                'lr_lookup_status' => Recipient::LR_FOUND,
                'lr_fetched_at'    => Carbon::now(),
                'lr_last_error'    => null,
            ])->save();

            return $recipient;
        }

        if ($result['status'] === self::NOT_FOUND) {
            $recipient->forceFill([
                'lr_lookup_status' => Recipient::LR_NOT_FOUND,
                'lr_fetched_at'    => Carbon::now(),
                'lr_last_error'    => null,
            ])->save();

            return $recipient;
        }

        Log::warning('NATCON awardee lookup failed', [
            'recipient_id' => $recipient->id,
            'http_status'  => $result['http_status'],
            'error'        => $result['error'],
        ]);

        $recipient->forceFill([
            'lr_lookup_status' => Recipient::LR_ERROR,
            'lr_fetched_at'    => Carbon::now(),
            'lr_last_error'    => $result['error'],
        ])->save();

        return $recipient;
    }

    /**
     * LR camelCase -> our snake_case columns.
     *
     * Their `email` is deliberately ignored: ours is the identity key, and a
     * mismatch (they normalize differently, or the record was re-keyed) must not
     * silently repoint the row at a different address.
     *
     * @param  array<string,mixed>  $a
     * @return array<string,mixed>
     */
    public function mapAwardee(array $a): array
    {
        $photos = [];
        foreach ((array) ($a['photos'] ?? []) as $url) {
            if (is_string($url) && trim($url) !== '') {
                $photos[] = trim($url);
            }
        }

        return [
            'lr_awardee_id'      => isset($a['id']) ? (int) $a['id'] : null,
            'reg_id'             => $this->str($a['regId'] ?? null, 64),
            'first_name'         => $this->str($a['firstName'] ?? null, 191),
            'last_name'          => $this->str($a['lastName'] ?? null, 191),
            'phone'              => $this->str($a['phone'] ?? null, 32),
            'team'               => $this->str($a['team'] ?? null, 191),
            'owner_name'         => $this->str($a['owner'] ?? null, 191),
            'seat_number'        => $this->str($a['seatNumber'] ?? null, 32),
            'lr_polo_shirt_size' => $this->str($a['poloShirtSize'] ?? null, 16),
            'lr_approved'        => isset($a['approved']) ? (bool) $a['approved'] : null,
            'lr_photos'          => array_values(array_unique($photos)),
            'lr_primary_photo'   => $this->str($a['photo'] ?? null, 2048),
            'lr_qr_code'         => $this->str($a['qrCode'] ?? null, 512),
            'lr_payload'         => $a,
        ];
    }

    private function str($value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr(trim((string) $value), 0, $max);
    }

    public function forget(string $email): void
    {
        Cache::forget(self::cacheKey($email));
    }

    public static function cacheKey(string $email): string
    {
        return self::CACHE_PREFIX . sha1(strtolower(trim($email)));
    }

    /**
     * Seconds to sleep between lookups to stay inside LR's 60/min ceiling.
     * We target half of it by default, leaving headroom for whatever else on
     * api2 talks to them from the same egress IP.
     */
    public static function throttleMicroseconds(): int
    {
        $perMinute = max(1, (int) config('natcon.lr.lookups_per_minute', 30));

        return (int) round(60_000_000 / $perMinute);
    }
}
