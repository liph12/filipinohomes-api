<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Absolute URL for an image used inside an email (icons in
 * resources/views/components/email/*). Emails are opened in Gmail/Outlook,
 * which fetch images from the public internet — so a local/dev APP_URL
 * (localhost, 127.0.0.1, *.test) would show broken icons. In that case the
 * same file is taken from the live API instead (public/images/email/* is
 * deployed there). MAIL_ASSET_URL overrides both.
 */
class EmailAsset
{
    public const FALLBACK_HOST = 'https://api2.filipinohomes.com';

    public static function url(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }

    /**
     * Avatar for an email person card: a square crop from /email/avatar
     * (EmailAvatarController) so Gmail/Outlook don't squash it. When the API
     * runs on a developer machine that route can't be reached by Gmail (and
     * the live API may not have it yet), so the original photo is used as-is.
     */
    public static function avatar(string $url): string
    {
        if (config('mail.asset_url') === null && self::appUrlIsLocal()) {
            return $url;
        }

        return self::url('email/avatar').'?u='.urlencode($url);
    }

    public static function appUrlIsLocal(): bool
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)
            || Str::endsWith($host, ['.test', '.localhost', '.local']);
    }

    public static function base(): string
    {
        $configured = (string) config('mail.asset_url');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return self::appUrlIsLocal() ? self::FALLBACK_HOST : rtrim((string) config('app.url'), '/');
    }
}
