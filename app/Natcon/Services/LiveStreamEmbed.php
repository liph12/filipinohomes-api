<?php

namespace App\Natcon\Services;

/**
 * Turns the link an admin pastes into something an <iframe> can play.
 *
 * YouTube: watch?v=, youtu.be/, /live/, /shorts/, /embed/ and a channel's /live page → the
 * youtube-nocookie embed. Facebook: any facebook.com / fb.watch video or live URL → the Facebook
 * video plugin (public videos only — a private group's stream will not play). Anything else is kept as a plain link
 * with no embed. Both embed hosts are already in the frontend's CSP frame-src.
 */
class LiveStreamEmbed
{
    /** The host of the public site, for YouTube's embed_domain (localhost while developing). */
    private static function embedDomain(): string
    {
        $host = parse_url((string) config('services.frontend.url', 'https://filipinohomes.com'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? preg_replace('/^www\./', '', $host) : 'filipinohomes.com';
    }

    /** Is this a YouTube or Facebook link — the only two the live page can embed? */
    public static function isSupported(?string $url): bool
    {
        $embed = self::for($url);

        return $embed !== null && $embed['provider'] !== null;
    }

    /**
     * @return array{provider: 'youtube'|'facebook'|null, video_id?: string, embed_url: string|null, chat_url?: string, watch_url: string}|null
     */
    public static function for(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower(preg_replace('/^www\./', '', $parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        // YouTube
        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            $id = null;
            if ($host === 'youtu.be') {
                $id = trim($path, '/');
            } elseif (isset($query['v']) && is_string($query['v'])) {
                $id = $query['v'];
            } elseif (preg_match('#^/(?:live|shorts|embed|v)/([A-Za-z0-9_-]{6,})#', $path, $m)) {
                $id = $m[1];
            } elseif (preg_match('#^/channel/(UC[A-Za-z0-9_-]{10,})/live/?$#', $path, $m)) {
                // A channel's permanent "live" page: YouTube resolves it to whatever is streaming now.
                return ['provider' => 'youtube', 'embed_url' => 'https://www.youtube-nocookie.com/embed/live_stream?channel='.$m[1].'&autoplay=1', 'watch_url' => $url];
            }
            if ($id !== null && preg_match('/^[A-Za-z0-9_-]{6,}$/', $id)) {
                return [
                    'provider' => 'youtube',
                    'video_id' => $id,
                    'embed_url' => 'https://www.youtube-nocookie.com/embed/'.$id.'?autoplay=1&rel=0',
                    // YouTube's embeddable live chat — the one platform that offers one. It needs the video id
                    // (a channel /live link has none) and the embedding page's host in embed_domain.
                    'chat_url' => 'https://www.youtube.com/live_chat?v='.$id.'&embed_domain='.rawurlencode(self::embedDomain()).'&dark_theme=1',
                    'watch_url' => $url,
                ];
            }

            return ['provider' => 'youtube', 'embed_url' => null, 'watch_url' => $url];
        }

        // Facebook
        if (in_array($host, ['facebook.com', 'm.facebook.com', 'web.facebook.com', 'fb.watch', 'fb.com'], true)) {
            return [
                'provider' => 'facebook',
                'embed_url' => 'https://www.facebook.com/plugins/video.php?href='.rawurlencode($url).'&show_text=false&autoplay=true&allowfullscreen=true',
                'watch_url' => $url,
            ];
        }

        return ['provider' => null, 'embed_url' => null, 'watch_url' => $url];
    }
}
