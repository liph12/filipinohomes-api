<?php

namespace App\Support;

use App\Services\Audience\AiVisibilityService;

/**
 * Which marketing channel a visit came from (visits.channel), in priority order:
 *   1. utm_source — explicit tag the marketer added (wins).
 *   2. click_id   — the tracking param the platform auto-appends even to a bare shared link (fbclid,
 *                   gclid, ttclid…), so a link pasted straight into Facebook still counts as Facebook.
 *   3. referrer host — fallback when neither tag survives.
 *   4. direct — no signal at all.
 * AI assistants are checked first at each step, one channel per assistant (chatgpt, gemini…).
 *
 * Hosts are matched by DOMAIN (the host is the domain or a subdomain of it), never by "contains these
 * letters": the old substring match read chatgpT.COm, copiloT.COm, reddiT.COm and yandeX.COM as Twitter,
 * and any host containing "ig" as Instagram. Android app referrers (android-app://com.twitter.android)
 * arrive as a package-name host and are matched by package prefix.
 */
class ChannelMatcher
{
    /** Per channel, in match order: domains, Android package prefixes, and utm_source words. */
    private const CHANNELS = [
        'facebook' => [
            'domains' => ['facebook.com', 'fb.com', 'fb.me', 'messenger.com'], // messenger: l.messenger.com link shim on desktop
            'apps' => ['com.facebook.'],
            'words' => ['facebook', 'fb', 'messenger'],
        ],
        'instagram' => ['domains' => ['instagram.com'], 'apps' => ['com.instagram.'], 'words' => ['instagram', 'ig']],
        'tiktok' => ['domains' => ['tiktok.com'], 'apps' => ['com.zhiliaoapp.musically', 'com.ss.android.ugc.'], 'words' => ['tiktok']],
        'twitter' => ['domains' => ['twitter.com', 'x.com', 't.co'], 'apps' => ['com.twitter.'], 'words' => ['twitter']],
        'youtube' => ['domains' => ['youtube.com', 'youtu.be'], 'apps' => ['com.google.android.youtube'], 'words' => ['youtube']],
        // google.com, google.com.ph, google.co.uk… plus the ads hosts. Before email, so mail.google.com
        // stays Google as it always has.
        'google' => [
            'patterns' => ['/(^|\.)google\.[a-z]{2,3}(\.[a-z]{2})?$/'],
            'domains' => ['googleadservices.com', 'googlesyndication.com'],
            'apps' => ['com.google.'],
            'words' => ['google', 'googleads', 'adwords'],
        ],
        'bing' => ['domains' => ['bing.com'], 'apps' => [], 'words' => ['bing']],
        // Unchanged from the old map: Yahoo (including Yahoo Search) counts as email.
        'email' => [
            'patterns' => ['/(^|\.)yahoo\.[a-z]{2,3}(\.[a-z]{2})?$/', '/^(web)?mail\./'],
            'domains' => ['gmail.com', 'outlook.com', 'outlook.live.com', 'outlook.office.com', 'outlook.office365.com'],
            'apps' => [],
            'words' => ['email', 'newsletter', 'mail', 'gmail', 'outlook', 'yahoo'],
        ],
    ];

    public static function resolve(?string $utmSource, ?string $clickId, ?string $referrer): string
    {
        $ai = app(AiVisibilityService::class);

        $src = strtolower(trim((string) $utmSource));
        if ($src !== '') {
            return $ai->classifyClick($src, null) ?? self::matchSource($src) ?? 'referral';
        }

        $clickChannel = match (strtolower(trim((string) $clickId))) {
            'fbclid' => 'facebook',
            'gclid', 'gbraid', 'wbraid' => 'google',
            'ttclid' => 'tiktok',
            'twclid' => 'twitter',
            'msclkid' => 'bing',
            default => null,
        };
        if ($clickChannel) {
            return $clickChannel;
        }

        $host = strtolower((string) parse_url((string) $referrer, PHP_URL_HOST));
        if ($host === '') {
            return 'direct';
        }

        return $ai->classifyClick(null, $referrer) ?? self::matchHost($host) ?? 'referral';
    }

    /** A referrer host: the channel whose domain it is (or a subdomain of), or whose app it is. */
    public static function matchHost(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));
        foreach (self::CHANNELS as $channel => $c) {
            foreach ($c['patterns'] ?? [] as $re) {
                if (preg_match($re, $host)) {
                    return $channel;
                }
            }
            foreach ($c['domains'] as $d) {
                if ($host === $d || str_ends_with($host, '.'.$d)) {
                    return $channel;
                }
            }
            foreach ($c['apps'] as $pkg) {
                if (str_starts_with($host, $pkg) || $host === rtrim($pkg, '.')) {
                    return $channel;
                }
            }
        }

        return null;
    }

    /**
     * A utm_source value: a domain ("facebook.com") is matched like a host; otherwise by its words
     * ("facebook_page" → facebook, "fb" → facebook, "ig" → instagram). A word also matches when it starts
     * with a channel word of five letters or more ("facebookads").
     */
    public static function matchSource(string $src): ?string
    {
        $src = strtolower(trim($src));
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $src)) {
            return self::matchHost($src);
        }
        $words = preg_split('/[^a-z0-9]+/', $src, -1, PREG_SPLIT_NO_EMPTY);
        foreach (self::CHANNELS as $channel => $c) {
            foreach ($c['words'] as $w) {
                foreach ($words as $word) {
                    if ($word === $w || (strlen($w) >= 5 && str_starts_with($word, $w))) {
                        return $channel;
                    }
                }
            }
        }

        return null;
    }
}
