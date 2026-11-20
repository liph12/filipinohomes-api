<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Is the website's email going out, and if not, why — for System Settings →
 * Email Health (GET /admin/mail-health).
 *
 * SMTP can't be asked how much sending is left; the server only says so when a
 * send is refused. So this reads what already happened: every send and every
 * failure is in `audits` (category `mailer`, AuditMailService), each failure
 * with the server's own reply, and classify() turns that reply into a reason:
 *
 *   limit        "451 4.7.1 Ratelimit … exceeded", Gmail "5.4.5 Daily user sending limit"
 *   blocked      "554 5.7.1 Outbound sending is disabled for this account"
 *   auth         "535 Authentication failed" — wrong username / password
 *   unreachable  the server didn't answer or dropped the connection
 *   recipient    one bad address ("Recipient address rejected") — not an account problem
 *   other        anything else
 *
 * status() is the account's state now: the reason of the latest account-level
 * failure (anything but `recipient`) when nothing has gone out since it,
 * `partial` when some sends fail and some go through, `ok` otherwise, `idle`
 * when nothing was sent or failed in the window.
 */
class MailHealthService
{
    public const WINDOW_HOURS = 24;

    /** A failure this recent, with sends since, still marks the account as partly failing. */
    public const PARTIAL_MINUTES = 60;

    public const REASONS = ['limit', 'blocked', 'auth', 'unreachable', 'recipient', 'other'];

    /** Reasons that are about the sending account, not one message. */
    private const ACCOUNT_LEVEL = ['limit', 'blocked', 'auth', 'unreachable'];

    /** Order matters: a "rejected … rate limit" reply is a limit, not a bad recipient. */
    private const PATTERNS = [
        'limit' => '/rate ?limit|sending limit|daily (user )?sending|quota|too many (messages|emails|recipients)|limit exceeded|5\.4\.5|4\.7\.0 .*try again later/i',
        'blocked' => '/outbound sending is disabled|sending (is )?(disabled|suspended|blocked)|account (is |has been )?(disabled|suspended|blocked|locked)|5\.7\.1 .*(disabled|suspend|block)/i',
        'auth' => '/authenticat|username and password not accepted|invalid (login|credentials)|\b535\b|5\.7\.8|5\.7\.9/i',
        'unreachable' => '/connection (could not be established|.*closed|timed out|refused|reset)|unable to connect|failed to enable crypto|stream_socket|getaddrinfo|network is unreachable|timed out/i',
        'recipient' => '/recipient address rejected|user unknown|no such user|mailbox (unavailable|not found)|5\.1\.[0-9]|fully-qualified|invalid (recipient|address)|does not comply with RFC/i',
    ];

    public function classify(?string $reply): string
    {
        $reply = (string) $reply;
        foreach (self::PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $reply)) {
                return $reason;
            }
        }

        return 'other';
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $now = Carbon::now();
        $since = $now->copy()->subHours(self::WINDOW_HOURS);
        $base = fn () => DB::table('audits')->where('category', 'mailer')->where('created_at', '>=', $since);

        $sent = (int) $base()->where('event', 'mailer_sent')->count();
        $lastSentAt = DB::table('audits')->where('category', 'mailer')->where('event', 'mailer_sent')->max('created_at');

        // Failures are few (a bad day on prod was ~70), so read them and classify here.
        $failures = $base()->where('event', 'mailer_failed')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(1000)
            ->get(['created_at', 'source', 'new_values'])
            ->map(function ($row) {
                $values = json_decode((string) $row->new_values, true) ?: [];
                $reply = (string) ($values['smtp_response'] ?? $values['exception_message'] ?? '');

                return [
                    'at' => (string) $row->created_at,
                    'reason' => $this->classify($reply),
                    'reply' => mb_substr($this->serverMessage($reply), 0, 300),
                    'email' => class_basename((string) ($values['mailable_class'] ?? $row->source ?? '')),
                ];
            });

        $reasons = [];
        foreach (self::REASONS as $reason) {
            $mine = $failures->where('reason', $reason);
            if ($mine->isEmpty()) {
                continue;
            }
            $latest = $mine->first();
            $reasons[] = [
                'reason' => $reason,
                'count' => $mine->count(),
                'last_at' => $this->iso($latest['at']),
                'last_reply' => $latest['reply'],
                'last_email' => $latest['email'],
            ];
        }
        usort($reasons, fn ($a, $b) => $b['count'] <=> $a['count']);

        $lastFailure = $failures->first();
        $lastAccountFailure = $failures->first(fn ($f) => in_array($f['reason'], self::ACCOUNT_LEVEL, true));

        if ($lastAccountFailure !== null && ($lastSentAt === null || $lastSentAt < $lastAccountFailure['at'])) {
            $status = $lastAccountFailure['reason'];
        } elseif ($lastAccountFailure !== null && Carbon::parse($lastAccountFailure['at'])->gte($now->copy()->subMinutes(self::PARTIAL_MINUTES))) {
            $status = 'partial';
        } elseif ($sent === 0 && $failures->isEmpty()) {
            $status = 'idle';
        } else {
            $status = 'ok';
        }

        $mailer = (string) config('mail.default');

        return [
            'status' => $status,
            // The reason behind the status (the latest account-level failure), when there is one.
            'cause' => in_array($status, ['ok', 'idle'], true) || $lastAccountFailure === null ? null : [
                'reason' => $lastAccountFailure['reason'],
                'at' => $this->iso($lastAccountFailure['at']),
                'reply' => $lastAccountFailure['reply'],
                'email' => $lastAccountFailure['email'],
            ],
            'window_hours' => self::WINDOW_HOURS,
            'sent' => $sent,
            'failed' => $failures->count(),
            'last_sent_at' => $this->iso($lastSentAt),
            'last_failed_at' => $this->iso($lastFailure['at'] ?? null),
            'reasons' => $reasons,
            // Which SMTP account sends (MAIL_USERNAME), so admins know which one to check.
            'sending_via' => [
                'account' => config("mail.mailers.{$mailer}.username"),
                'host' => config("mail.mailers.{$mailer}.host"),
                'from' => config('mail.from.address'),
            ],
            'checked_at' => $now->toIso8601String(),
        ];
    }

    /**
     * The server's own words: Symfony wraps them as `Expected response code "250"
     * but got code "554", with message "554 5.7.1 …"` — keep the part after "message".
     */
    private function serverMessage(string $reply): string
    {
        return preg_match('/with message "(.*)"\.?\s*$/s', trim($reply), $m) ? trim($m[1]) : trim($reply);
    }

    private function iso(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
