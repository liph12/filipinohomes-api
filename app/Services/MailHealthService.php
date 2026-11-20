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
 *   sender       "553 5.7.1 Sender address rejected: not owned by user" — MAIL_FROM_ADDRESS
 *                isn't the account's own address
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

    public const REASONS = ['limit', 'blocked', 'auth', 'sender', 'unreachable', 'recipient', 'other'];

    /** Reasons that are about the sending account, not one message. */
    private const ACCOUNT_LEVEL = ['limit', 'blocked', 'auth', 'sender', 'unreachable'];

    /** Order matters: a "rejected … rate limit" reply is a limit, not a bad recipient. */
    private const PATTERNS = [
        'limit' => '/rate ?limit|sending limit|daily (user )?sending|quota|too many (messages|emails|recipients)|limit exceeded|5\.4\.5|4\.7\.0 .*try again later/i',
        'blocked' => '/outbound sending is disabled|sending (is )?(disabled|suspended|blocked)|account (is |has been )?(disabled|suspended|blocked|locked)|5\.7\.1 .*(disabled|suspend|block)/i',
        'auth' => '/authenticat|username and password not accepted|invalid (login|credentials)|\b535\b|5\.7\.8|5\.7\.9/i',
        'sender' => '/sender address rejected|not owned by user|sender (address )?(is )?not allowed|SendAsDenied|5\.7\.60|mail from .*(rejected|denied)/i',
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

        // Every logged send/failure says which SMTP account it went through
        // ("host - username", AuditMailService::smtpAccount). Two servers with
        // different .env accounts write into this one log, so the status is
        // this server's account; any other account is reported beside it.
        // Rows from before the account was logged have none and are left out.
        $current = AuditMailService::smtpAccount(null);
        $smtp = $this->jsonField('smtp');

        $sentBy = $base()->where('event', 'mailer_sent')
            ->selectRaw("{$smtp} as smtp, COUNT(*) as n, MAX(created_at) as last_at")
            ->groupByRaw($smtp)
            ->get()
            ->filter(fn ($row) => $row->smtp !== null && $row->smtp !== '')
            ->keyBy('smtp');

        // Failures are few (a bad day on prod was ~90), so read them and classify here.
        $failures = $base()->where('event', 'mailer_failed')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(1000)
            ->get(['created_at', 'source', 'new_values'])
            ->map(function ($row) {
                $values = json_decode((string) $row->new_values, true) ?: [];
                $reply = (string) ($values['smtp_response'] ?? $values['exception_message'] ?? '');

                return [
                    'at' => (string) $row->created_at,
                    'smtp' => $values['smtp'] ?? null,
                    'reason' => $this->classify($reply),
                    'reply' => mb_substr($this->serverMessage($reply), 0, 300),
                    'email' => class_basename((string) ($values['mailable_class'] ?? $row->source ?? '')),
                ];
            })
            ->filter(fn ($f) => $f['smtp'] !== null && $f['smtp'] !== '');

        $mine = $failures->where('smtp', $current)->values();
        $sent = (int) ($sentBy[$current]->n ?? 0);
        $lastSentAt = $sentBy[$current]->last_at ?? null;

        $reasons = [];
        foreach (self::REASONS as $reason) {
            $ofReason = $mine->where('reason', $reason);
            if ($ofReason->isEmpty()) {
                continue;
            }
            $latest = $ofReason->first();
            $reasons[] = [
                'reason' => $reason,
                'count' => $ofReason->count(),
                'last_at' => $this->iso($latest['at']),
                'last_reply' => $latest['reply'],
                'last_email' => $latest['email'],
            ];
        }
        usort($reasons, fn ($a, $b) => $b['count'] <=> $a['count']);

        $lastFailure = $mine->first();
        $lastAccountFailure = $mine->first(fn ($f) => in_array($f['reason'], self::ACCOUNT_LEVEL, true));

        if ($lastAccountFailure !== null && ($lastSentAt === null || $lastSentAt < $lastAccountFailure['at'])) {
            $status = $lastAccountFailure['reason'];
        } elseif ($lastAccountFailure !== null && Carbon::parse($lastAccountFailure['at'])->gte($now->copy()->subMinutes(self::PARTIAL_MINUTES))) {
            $status = 'partial';
        } elseif ($sent === 0 && $mine->isEmpty()) {
            $status = 'idle';
        } else {
            $status = 'ok';
        }

        // Other accounts seen in the window (another server's .env), with how they fared.
        $others = [];
        foreach ($sentBy->keys()->merge($failures->pluck('smtp'))->unique() as $account) {
            if ($account === $current) {
                continue;
            }
            $theirs = $failures->where('smtp', $account)->values();
            $latest = $theirs->first();
            $others[] = [
                'smtp' => $account,
                'sent' => (int) ($sentBy[$account]->n ?? 0),
                'failed' => $theirs->count(),
                'last_sent_at' => $this->iso($sentBy[$account]->last_at ?? null),
                'last_failed_at' => $this->iso($latest['at'] ?? null),
                'last_reason' => $latest['reason'] ?? null,
                'last_reply' => $latest['reply'] ?? null,
            ];
        }
        usort($others, fn ($a, $b) => $b['failed'] <=> $a['failed']);

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
            'failed' => $mine->count(),
            'last_sent_at' => $this->iso($lastSentAt),
            'last_failed_at' => $this->iso($lastFailure['at'] ?? null),
            'reasons' => $reasons,
            'other_accounts' => $others,
            // Which SMTP account sends (MAIL_USERNAME), so admins know which one to check.
            'sending_via' => [
                'account' => config("mail.mailers.{$mailer}.username"),
                'host' => config("mail.mailers.{$mailer}.host"),
                'from' => config('mail.from.address'),
            ],
            'checked_at' => $now->toIso8601String(),
        ];
    }

    /** A top-level key of the audits' new_values JSON, as SQL (MySQL in prod, SQLite in tests). */
    private function jsonField(string $key): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(new_values, '$.{$key}')"
            // JSON_VALID: one malformed row must not fail the whole check.
            : "IF(JSON_VALID(new_values), JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.{$key}')), NULL)";
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
