<?php

/*
|--------------------------------------------------------------------------
| Admin Mailbox — the info@filipinohomes.com inbox inside the dashboard
|--------------------------------------------------------------------------
|
| Deliberately separate from MAIL_*. MAIL_* is the app's outgoing mailer and
| gets switched between providers (Gmail, SES, …); the mailbox must keep
| reading and sending as info@ no matter what MAIL_* points at, so it has its
| own IMAP login and its own SMTP transport (the "mailbox" mailer in
| config/mail.php). The mailbox is hosted on Hostinger (MX mx1/mx2.hostinger.com).
|
| SMTP falls back to the IMAP login, so normally only MAILBOX_USERNAME and
| MAILBOX_PASSWORD need setting.
|
*/

return [

    'username' => env('MAILBOX_USERNAME', 'info@filipinohomes.com'),
    'password' => env('MAILBOX_PASSWORD'),

    'from_name' => env('MAILBOX_FROM_NAME', 'Filipino Homes'),

    'imap' => [
        'host' => env('MAILBOX_IMAP_HOST', 'imap.hostinger.com'),
        'port' => (int) env('MAILBOX_IMAP_PORT', 993),
        'encryption' => env('MAILBOX_IMAP_ENCRYPTION', 'ssl'),
        'timeout' => (int) env('MAILBOX_IMAP_TIMEOUT', 20),
    ],

    'smtp' => [
        'host' => env('MAILBOX_SMTP_HOST', 'smtp.hostinger.com'),
        'port' => (int) env('MAILBOX_SMTP_PORT', 465),
        // 465 = implicit TLS ("smtps"); 587 = STARTTLS ("smtp").
        'scheme' => env('MAILBOX_SMTP_SCHEME', 'smtps'),
    ],

    // Which website emails the dashboard shows (by the day they arrived):
    //   before `since`                      → hidden
    //   `since` … day before tag_required_from → a matching subject is enough
    //   from `tag_required_from` on            → must also carry the X-FH-Mailer
    //                                            header our app stamps on its mail
    'since' => env('MAILBOX_SINCE', '2026-04-01'),
    'tag_required_from' => env('MAILBOX_TAG_REQUIRED_FROM', '2026-11-16'),

    // Compose limits.
    'max_recipients' => 50,
    'max_attachment_mb' => 20, // total per message
];
