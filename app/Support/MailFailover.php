<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\AuditMailService;
use Throwable;

/**
 * The website's SMTP accounts and the order they're tried in (MAIL_MAILER=failover,
 * config/mail.php). .env lists the accounts and the default order (MAIL_FAILOVER);
 * System Settings → Email Health can put another account first, saved in the
 * settings table so both servers follow it without a deploy.
 */
class MailFailover
{
    public const FIRST_KEY = 'mail.failover_first';

    /** Is the default mailer the failover one? */
    public static function enabled(): bool
    {
        $default = (string) config('mail.default');

        return config("mail.mailers.{$default}.transport") === 'fh_failover';
    }

    /** The failover mailer's accounts in .env order (only ones that exist and have a host). */
    public static function configured(): array
    {
        $default = (string) config('mail.default');
        $names = self::enabled() ? (array) config("mail.mailers.{$default}.mailers", []) : [];

        return array_values(array_filter(
            array_unique($names),
            fn ($name) => is_string($name) && (string) config("mail.mailers.{$name}.host") !== '',
        ));
    }

    /** The account System Settings put first, or null to keep the .env order. */
    public static function chosenFirst(): ?string
    {
        try {
            $value = Setting::get(self::FIRST_KEY);
        } catch (Throwable) {
            return null; // no settings table / DB hiccup: the .env order still works
        }

        return is_string($value) && in_array($value, self::configured(), true) ? $value : null;
    }

    /** The order the accounts are tried in now. */
    public static function order(): array
    {
        $order = self::configured();
        $first = self::chosenFirst();
        if ($first !== null) {
            $order = array_values(array_unique([$first, ...$order]));
        }

        return $order;
    }

    public static function setFirst(string $name): void
    {
        Setting::set(self::FIRST_KEY, $name);
    }

    /** "Gmail" — the mailer's label, else its host. */
    public static function label(string $mailer): string
    {
        return (string) (config("mail.mailers.{$mailer}.label") ?: config("mail.mailers.{$mailer}.host") ?: $mailer);
    }

    /**
     * This server's sending accounts in the order they're tried: the failover's
     * accounts, or the single default mailer.
     *
     * @return array<int, array{name: string, label: string, smtp: string|null}>
     */
    public static function accounts(): array
    {
        $names = self::enabled() ? self::order() : [(string) config('mail.default')];

        return array_map(fn ($name) => [
            'name' => $name,
            'label' => self::label($name),
            'smtp' => AuditMailService::smtpAccount($name),
        ], $names);
    }
}
