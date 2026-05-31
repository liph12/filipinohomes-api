<?php

namespace App\Mail\Concerns;

use Symfony\Component\Mime\Email;

/**
 * Stamps the outbound message with an `X-FH-Mailer` header containing
 * the Mailable subclass's short name (e.g. `LoginOtpMailer`,
 * `MessageNotificationMailer`). AuditMailService reads this header
 * to populate the `source` column on the audit row — without it, the
 * audit row falls back to `source: 'unknown'` and the activity-logs
 * feed can't filter or attribute mail sends to a specific mailer.
 *
 * Call `$this->tagFhMailerHeader()` from the Mailable's __construct.
 * Mailable has no boot hook, so this must be invoked explicitly.
 */
trait TagsFhMailerHeader
{
    public function tagFhMailerHeader(): static
    {
        $this->withSymfonyMessage(function (Email $email) {
            $email->getHeaders()->addTextHeader(
                'X-FH-Mailer',
                class_basename(static::class),
            );
        });

        return $this;
    }
}
