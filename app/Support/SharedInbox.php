<?php

namespace App\Support;

/**
 * The shared mailbox (info@filipinohomes.com) and the addresses that ARE it.
 *
 * Fan-out emails go To: the shared inbox with the real recipients (admins,
 * team leaders, agents) in BCC. When one of those recipients is the shared
 * mailbox itself — an admin account whose email is info@, or the account the
 * app sends through (the SMTP logins — with MAIL_MAILER=failover both info@
 * and the IT Gmail — and MAIL_FROM_ADDRESS) — the mail server delivers one
 * more identical copy to that mailbox. That's how one Contact Us submission
 * landed in info@ 2–6 times. bccWithout() drops those addresses: the To copy
 * already reaches the mailbox.
 */
final class SharedInbox
{
    public const ADDRESS = 'info@filipinohomes.com';

    /**
     * Lower-cased addresses that are the shared mailbox: info@, the current
     * From address, the SMTP login(s) the app sends through (every failover
     * account — which one delivers isn't known until it's sent), any address that forwards into
     * info@ (mail.shared_inbox_aliases / MAIL_SHARED_INBOX_ALIASES), plus any
     * $to used for this send.
     *
     * @return string[]
     */
    public static function addresses(?string $to = null): array
    {
        $logins = array_map(
            fn (array $account) => config("mail.mailers.{$account['name']}.username"),
            MailFailover::accounts(),
        );

        return array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            [
                self::ADDRESS,
                $to,
                config('mail.from.address'),
                ...$logins,
                ...(array) config('mail.shared_inbox_aliases', []),
            ],
        ), fn ($e) => $e !== '' && str_contains($e, '@'))));
    }

    /**
     * BCC list minus the shared mailbox (and duplicates, case-insensitive).
     *
     * @param  iterable<string|null>  $emails
     * @return string[]
     */
    public static function bccWithout(iterable $emails, ?string $to = null): array
    {
        $skip = self::addresses($to);
        $out = [];
        foreach ($emails as $email) {
            $email = trim((string) $email);
            $key = strtolower($email);
            if ($email === '' || in_array($key, $skip, true) || isset($out[$key])) {
                continue;
            }
            $out[$key] = $email;
        }

        return array_values($out);
    }

    /** Is this address the shared mailbox (info@, the SMTP account, or an alias into it)? */
    public static function is(?string $email): bool
    {
        return in_array(strtolower(trim((string) $email)), self::addresses(), true);
    }
}
