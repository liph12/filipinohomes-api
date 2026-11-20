<?php

namespace App\Services;

use App\Mail\Transport\MailFailoverException;
use App\Models\User;
use App\Support\MailFailover;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Tells admins when the website's email stops going out: an email failed on
 * every SMTP account for an account-level reason (limit, blocked, login,
 * sender, unreachable — not one bad recipient address). By push + the app's
 * notification feed, since email itself is what's broken. At most once every
 * ALERT_HOURS, so a long outage doesn't flood anyone. Sent after the response
 * (defer), so the request that hit the failure isn't slowed down.
 */
class MailDownAlertService
{
    public const ALERT_HOURS = 6;

    private const CACHE_KEY = 'mail-down-alert';

    public function failed(TransportExceptionInterface $error): void
    {
        try {
            $health = app(MailHealthService::class);
            $failures = $error instanceof MailFailoverException
                ? $error->failures()
                : [(string) config('mail.default') => $error];

            $lines = [];
            foreach ($failures as $mailer => $e) {
                $reason = $health->classify($e->getMessage());
                if (! in_array($reason, MailHealthService::ACCOUNT_LEVEL, true)) {
                    return; // a bad address, not a broken account
                }
                $lines[] = MailFailover::label((string) $mailer).': '.MailHealthService::LABELS[$reason];
            }
            if ($lines === [] || ! Cache::add(self::CACHE_KEY, now()->toIso8601String(), now()->addHours(self::ALERT_HOURS))) {
                return;
            }

            $title = 'Website emails are not going out';
            $body = implode(' · ', $lines).'. Check System Settings → Email Health.';
            // always: the request that hit the failure often answers with an error status.
            defer(fn () => $this->notifyAdmins($title, $body), always: true);
        } catch (Throwable $e) {
            Log::warning('Mail-down alert failed', ['error' => $e->getMessage()]);
        }
    }

    public function notifyAdmins(string $title, string $body): void
    {
        $push = app(ExpoPushService::class);
        User::whereHas('role', fn ($q) => $q->where('name', 'admin'))
            ->get()
            ->each(function (User $admin) use ($push, $title, $body) {
                try {
                    $push->notify($admin, 'mail_down', $title, $body, ['type' => 'mail_down']);
                } catch (Throwable $e) {
                    Log::warning('Mail-down alert: notify failed', ['user_id' => $admin->id, 'error' => $e->getMessage()]);
                }
            });
    }
}
