<?php

namespace App\Support;

use Illuminate\Http\Request;

class DeviceLabel
{
    /**
     * Human-readable label for a login session, shown in the user's "active
     * devices" list. Prefers a client-supplied `device_name` (the mobile app
     * sends e.g. "Pixel 8 · Android"); otherwise derives a label from the
     * User-Agent so web sessions are still recognizable.
     */
    public static function fromRequest(Request $request): string
    {
        $name = trim((string) $request->input('device_name', ''));
        if ($name !== '') {
            return mb_substr($name, 0, 100);
        }

        return self::fromUserAgent($request->userAgent());
    }

    public static function fromUserAgent(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/')                       => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox')                    => 'Firefox',
            str_contains($ua, 'Chrome')                     => 'Chrome',
            str_contains($ua, 'Safari')                     => 'Safari',
            default                                          => null,
        };

        $os = match (true) {
            str_contains($ua, 'iPhone')                     => 'iPhone',
            str_contains($ua, 'iPad')                       => 'iPad',
            str_contains($ua, 'Android')                    => 'Android',
            str_contains($ua, 'Windows')                    => 'Windows',
            str_contains($ua, 'Mac OS') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux')                      => 'Linux',
            default                                          => null,
        };

        return match (true) {
            $browser && $os => "{$browser} on {$os}",
            (bool) $browser => $browser,
            (bool) $os      => $os,
            default         => 'Web browser',
        };
    }
}
