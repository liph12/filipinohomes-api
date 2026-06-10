<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VisitController extends Controller
{
    /**
     * Public acquisition ping. The web fires this once per session with the
     * referrer + utm params; we resolve a channel and store one row. Throttled
     * to one row per visitor per day so retries / extra mounts don't inflate.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id'   => 'nullable|string|max:64',
            'utm_source'   => 'nullable|string|max:128',
            'utm_medium'   => 'nullable|string|max:128',
            'utm_campaign' => 'nullable|string|max:191',
            'click_id'     => 'nullable|string|max:32',
            'referrer'     => 'nullable|string|max:512',
            'landing_path' => 'nullable|string|max:512',
        ]);

        $visitorId = $data['visitor_id'] ?? null;

        if ($visitorId) {
            $key = 'visit:' . $visitorId . ':' . now()->toDateString();
            if (!Cache::add($key, 1, now()->addDay())) {
                return response()->json(['ok' => true, 'deduped' => true]);
            }
        }

        $channel = $this->resolveChannel(
            $data['utm_source'] ?? null,
            $data['click_id'] ?? null,
            $data['referrer'] ?? null,
        );

        Visit::create([
            'visitor_id'   => $visitorId,
            'channel'      => $channel,
            'utm_source'   => $data['utm_source'] ?? null,
            'utm_medium'   => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'referrer'     => $data['referrer'] ?? null,
            'landing_path' => $data['landing_path'] ?? null,
            'user_id'      => $request->user()?->id,
            'ip'           => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'channel' => $channel]);
    }

    /**
     * Resolve a marketing channel, in priority order:
     *   1. utm_source — explicit tag the marketer added (wins).
     *   2. click_id   — the tracking param the platform auto-appends even to a
     *      bare shared link (fbclid, gclid, ttclid…), so a link pasted straight
     *      into Facebook still attributes to Facebook without manual tagging.
     *   3. referrer host — fallback when neither tag survives.
     *   4. direct — no signal at all.
     */
    private function resolveChannel(?string $utmSource, ?string $clickId, ?string $referrer): string
    {
        // 1. Explicit utm_source wins.
        $src = strtolower(trim((string) $utmSource));
        if ($src !== '') {
            return $this->matchChannel($src) ?? 'referral';
        }

        // 2. Platform click-id (auto-appended on bare shared links).
        //    Note: fbclid covers all of Meta — a bare link can't tell Instagram
        //    from Facebook, so it lands on facebook; tag utm_source=instagram to
        //    separate them.
        $clickChannel = match (strtolower(trim((string) $clickId))) {
            'fbclid'                    => 'facebook',
            'gclid', 'gbraid', 'wbraid' => 'google',
            'ttclid'                    => 'tiktok',
            'twclid'                    => 'twitter',
            'msclkid'                   => 'bing',
            default                     => null,
        };
        if ($clickChannel) {
            return $clickChannel;
        }

        // 3. Referrer host.
        $host = strtolower((string) parse_url((string) $referrer, PHP_URL_HOST));
        if ($host === '') {
            return 'direct';
        }

        return $this->matchChannel($host) ?? 'referral';
    }

    /** Match a utm_source token or referrer host against the channel map. */
    private function matchChannel(string $haystack): ?string
    {
        $map = [
            'facebook'  => ['facebook', 'fb', 'm.facebook', 'l.facebook', 'lm.facebook'],
            'instagram' => ['instagram', 'ig', 'l.instagram'],
            'tiktok'    => ['tiktok'],
            'twitter'   => ['twitter', 'x.com', 't.co'],
            'youtube'   => ['youtube', 'youtu.be'],
            'google'    => ['google', 'googleads', 'adwords'],
            'bing'      => ['bing'],
            'email'     => ['email', 'newsletter', 'mail.', 'gmail', 'outlook', 'yahoo'],
        ];

        foreach ($map as $channel => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $channel;
                }
            }
        }

        return null;
    }
}
