<?php

namespace App\Services;

use App\Support\MailFailover;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * With MAIL_MAILER=failover (MailFailover) an email can fail on one account and
 * still go out through the next: a sent row lists those failed attempts
 * (`failed_over`), a failed row lists every account it was tried on
 * (`attempts`). So there are two views:
 *
 *  - each account: its own state — the reason of its latest account-level
 *    failure when nothing went through it since, `partial` when it failed in
 *    the last hour but works again, `ok`, or `idle`;
 *  - the website (status()): `down` when the latest email that failed on every
 *    account is newer than the latest one delivered, `partial` when one did in
 *    the last hour, `fallback` when the first account is failing but the next
 *    one delivers, `ok`, or `idle` when nothing was sent in the window.
 */
class MailHealthService
{
    public const WINDOW_HOURS = 24;

    /** A failure this recent, with sends since, still counts as partly failing. */
    public const PARTIAL_MINUTES = 60;

    public const REASONS = ['limit', 'blocked', 'auth', 'sender', 'unreachable', 'recipient', 'other'];

    /** Reasons that are about the sending account, not one message. */
    public const ACCOUNT_LEVEL = ['limit', 'blocked', 'auth', 'sender', 'unreachable'];

    public const LABELS = [
        'limit' => 'sending limit reached',
        'blocked' => 'blocked by the email provider',
        'auth' => 'wrong username or password',
        'sender' => 'sender address not allowed',
        'unreachable' => 'mail server unreachable',
        'recipient' => 'bad recipient address',
        'other' => 'other error',
    ];

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

        // Every logged send/failure names its SMTP account ("host - username",
        // AuditMailService::smtpAccount). Two servers with different .env
        // accounts write into this one log, so this server's accounts are
        // reported in full and any other account beside them. Rows from before
        // the account was logged have none and are left out.
        $failover = MailFailover::enabled();
        $mine = MailFailover::accounts();
        $mySmtp = array_values(array_filter(array_column($mine, 'smtp')));
        $smtp = $this->jsonField('smtp');

        $sentBy = $base()->where('event', 'mailer_sent')
            ->selectRaw("{$smtp} as smtp, COUNT(*) as n, MAX(created_at) as last_at")
            ->groupByRaw($smtp)
            ->get()
            ->filter(fn ($row) => $row->smtp !== null && $row->smtp !== '')
            ->keyBy('smtp');

        // Failed tries, per account: from emails that failed outright and from
        // emails another account delivered after this one failed. Both are few
        // (a bad day on prod was ~90), so read them and classify here.
        $attempts = collect();
        $undelivered = collect();

        $failedOver = $this->jsonField('failed_over');
        $base()->where('event', 'mailer_sent')->whereRaw("{$failedOver} IS NOT NULL")
            ->orderByDesc('created_at')->orderByDesc('id')->limit(1000)
            ->get(['created_at', 'source', 'new_values'])
            ->each(function ($row) use ($attempts) {
                $values = json_decode((string) $row->new_values, true) ?: [];
                foreach ((array) ($values['failed_over'] ?? []) as $try) {
                    $attempts->push($this->attempt($row, $values, $try['smtp'] ?? null, (string) ($try['reply'] ?? ''), true));
                }
            });

        $base()->where('event', 'mailer_failed')
            ->orderByDesc('created_at')->orderByDesc('id')->limit(1000)
            ->get(['created_at', 'source', 'new_values'])
            ->each(function ($row) use ($attempts, $undelivered) {
                $values = json_decode((string) $row->new_values, true) ?: [];
                $tries = isset($values['attempts']) && is_array($values['attempts'])
                    ? $values['attempts']
                    // One account, or a row from before failover.
                    : [['smtp' => $values['smtp'] ?? null, 'reply' => (string) ($values['smtp_response'] ?? $values['exception_message'] ?? '')]];

                $mapped = collect($tries)
                    ->map(fn ($try) => $this->attempt($row, $values, $try['smtp'] ?? null, (string) ($try['reply'] ?? ''), false))
                    ->filter(fn ($a) => $a['smtp'] !== null && $a['smtp'] !== '')
                    ->values();
                if ($mapped->isEmpty()) {
                    return;
                }
                $attempts->push(...$mapped->all());
                $undelivered->push([
                    'at' => (string) $row->created_at,
                    'email' => $mapped[0]['email'],
                    'tries' => $mapped,
                    // Every account refused it for an account reason (not one bad address).
                    'account_level' => $mapped->contains(fn ($a) => in_array($a['reason'], self::ACCOUNT_LEVEL, true)),
                ]);
            });

        $attempts = $attempts->sortByDesc('at')->values();

        // ---- this server's accounts ---------------------------------------------
        $labelOf = collect($mine)->filter(fn ($a) => $a['smtp'])->mapWithKeys(fn ($a) => [$a['smtp'] => $a['label']]);
        $accounts = [];
        foreach ($mine as $i => $account) {
            $accounts[] = [
                'name' => $account['name'],
                'label' => $account['label'],
                'smtp' => $account['smtp'],
                'position' => $i + 1,
            ] + $this->accountState($account['smtp'], $sentBy, $attempts, $now);
        }

        // Emails this server tried: delivered through one of its accounts, or failed on them.
        $myUndelivered = $undelivered
            ->filter(fn ($u) => $u['tries']->contains(fn ($a) => in_array($a['smtp'], $mySmtp, true)))
            ->values();
        $sent = (int) collect($mySmtp)->sum(fn ($s) => (int) ($sentBy[$s]->n ?? 0));
        $lastSentAt = collect($mySmtp)->map(fn ($s) => $sentBy[$s]->last_at ?? null)->filter()->max();
        $lastDown = $myUndelivered->first(fn ($u) => $u['account_level']);

        $first = $accounts[0] ?? null;
        if ($lastDown !== null && ($lastSentAt === null || $lastSentAt < $lastDown['at'])) {
            $status = 'down';
        } elseif ($lastDown !== null && Carbon::parse($lastDown['at'])->gte($now->copy()->subMinutes(self::PARTIAL_MINUTES))) {
            $status = 'partial';
        } elseif ($failover && count($accounts) > 1 && in_array($first['status'], self::ACCOUNT_LEVEL, true)) {
            $status = 'fallback';
        } elseif ($sent === 0 && $myUndelivered->isEmpty()) {
            $status = 'idle';
        } else {
            $status = 'ok';
        }

        // The reason behind the status: the email that failed everywhere (with
        // each account's reply), or the first account's own latest failure.
        $cause = null;
        if (in_array($status, ['down', 'partial'], true)) {
            $tries = $lastDown['tries']->filter(fn ($a) => in_array($a['smtp'], $mySmtp, true))->values();
            $lead = $tries->first(fn ($a) => in_array($a['reason'], self::ACCOUNT_LEVEL, true)) ?? $tries[0];
            $cause = [
                'reason' => $lead['reason'],
                'account' => $labelOf[$lead['smtp']] ?? $lead['smtp'],
                'at' => $this->iso($lastDown['at']),
                'reply' => $lead['reply'],
                'email' => $lastDown['email'],
                'tries' => $tries->map(fn ($a) => [
                    'account' => $labelOf[$a['smtp']] ?? $a['smtp'],
                    'reason' => $a['reason'],
                    'reply' => $a['reply'],
                ])->all(),
            ];
        } elseif ($status === 'fallback') {
            $cause = [
                'reason' => $first['last_reason'],
                'account' => $first['label'],
                'at' => $first['last_failed_at'],
                'reply' => $first['last_reply'],
                'email' => $first['last_email'],
                'tries' => [],
            ];
        }

        // Failed tries on this server's accounts, by account and reason.
        $reasons = $attempts
            ->filter(fn ($a) => in_array($a['smtp'], $mySmtp, true))
            ->groupBy(fn ($a) => $a['smtp'].'|'.$a['reason'])
            ->map(function (Collection $group) use ($labelOf) {
                $latest = $group->first();

                return [
                    'reason' => $latest['reason'],
                    'account' => $labelOf[$latest['smtp']] ?? $latest['smtp'],
                    'count' => $group->count(),
                    // Delivered anyway through the next account.
                    'rescued' => $group->where('rescued', true)->count(),
                    'last_at' => $this->iso($latest['at']),
                    'last_reply' => $latest['reply'],
                    'last_email' => $latest['email'],
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        // Other accounts in the window (another server's .env), with how they fared.
        $others = [];
        foreach ($sentBy->keys()->merge($attempts->pluck('smtp'))->unique() as $account) {
            if (in_array($account, $mySmtp, true)) {
                continue;
            }
            $theirs = $attempts->where('smtp', $account)->values();
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

        return [
            'status' => $status,
            'cause' => $cause,
            'window_hours' => self::WINDOW_HOURS,
            // Emails delivered / emails that failed on every account tried.
            'sent' => $sent,
            'failed' => $myUndelivered->count(),
            'last_sent_at' => $this->iso($lastSentAt),
            'last_failed_at' => $this->iso($myUndelivered->first()['at'] ?? null),
            // Several accounts tried in order (MAIL_MAILER=failover), or one.
            'failover' => [
                'enabled' => $failover,
                // System Settings can only reorder when there's more than one account.
                'can_choose_first' => $failover && count($accounts) > 1,
                'chosen' => MailFailover::chosenFirst() !== null,
            ],
            'accounts' => $accounts,
            'reasons' => $reasons,
            'other_accounts' => $others,
            'from' => config('mail.from.address'),
            'checked_at' => $now->toIso8601String(),
        ];
    }

    /** One failed try on one account. */
    private function attempt(object $row, array $values, ?string $smtp, string $reply, bool $rescued): array
    {
        return [
            'at' => (string) $row->created_at,
            'smtp' => $smtp,
            'reason' => $this->classify($reply),
            'reply' => mb_substr($this->serverMessage($reply), 0, 300),
            'email' => class_basename((string) ($values['mailable_class'] ?? $row->source ?? '')),
            'rescued' => $rescued,
        ];
    }

    /** One account's state in the window. */
    private function accountState(?string $smtp, Collection $sentBy, Collection $attempts, Carbon $now): array
    {
        $fails = $smtp ? $attempts->where('smtp', $smtp)->values() : collect();
        $sent = (int) ($smtp ? ($sentBy[$smtp]->n ?? 0) : 0);
        $lastSentAt = $smtp ? ($sentBy[$smtp]->last_at ?? null) : null;
        $lastFail = $fails->first();
        $lastAccountFail = $fails->first(fn ($f) => in_array($f['reason'], self::ACCOUNT_LEVEL, true));

        if ($lastAccountFail !== null && ($lastSentAt === null || $lastSentAt < $lastAccountFail['at'])) {
            $status = $lastAccountFail['reason'];
        } elseif ($lastAccountFail !== null && Carbon::parse($lastAccountFail['at'])->gte($now->copy()->subMinutes(self::PARTIAL_MINUTES))) {
            $status = 'partial';
        } elseif ($sent === 0 && $fails->isEmpty()) {
            $status = 'idle';
        } else {
            $status = 'ok';
        }

        $shown = $lastAccountFail ?? $lastFail;

        return [
            'status' => $status,
            'sent' => $sent,
            'failed' => $fails->count(),
            'rescued' => $fails->where('rescued', true)->count(),
            'last_sent_at' => $this->iso($lastSentAt),
            'last_failed_at' => $this->iso($lastFail['at'] ?? null),
            'last_reason' => $shown['reason'] ?? null,
            'last_reply' => $shown['reply'] ?? null,
            'last_email' => $shown['email'] ?? null,
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
