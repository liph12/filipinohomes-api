<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeoLocation
{
    /**
     * Resolve an IP to a human "City, Region, Country" string for the
     * "active devices" list. Uses the free ip-api.com endpoint and caches
     * each successful lookup for 30 days so the sessions screen stays fast
     * and well under the free-tier rate limit. Private/local IPs and
     * lookup failures return null (the UI just omits the location).
     */
    public static function resolve(?string $ip): ?string
    {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $key = 'geoip:' . $ip;
        if (Cache::has($key)) {
            return Cache::get($key) ?: null;
        }

        try {
            $res = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,city,regionName,country',
            ]);

            if ($res->ok() && $res->json('status') === 'success') {
                $location = implode(', ', array_filter([
                    $res->json('city'),
                    $res->json('regionName'),
                    $res->json('country'),
                ]));
                Cache::put($key, $location, now()->addDays(30));

                return $location ?: null;
            }
        } catch (\Throwable $e) {
            // Network/timeout error — show no location rather than failing.
        }

        return null;
    }
}
