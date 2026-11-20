<?php

namespace App\Mail\Transport;

use App\Support\MailFailover;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Every SMTP account refused the email (FailoverTransport). The message names
 * each account with its server's reply; failures() keeps them apart for
 * AuditMailService / Email Health.
 */
class MailFailoverException extends TransportException
{
    /** @param  array<string, TransportExceptionInterface>  $failures  mailer => its error */
    public function __construct(private array $failures)
    {
        $parts = [];
        foreach ($failures as $mailer => $e) {
            $parts[] = MailFailover::label($mailer).': '.$e->getMessage();
        }

        parent::__construct($failures === []
            ? 'No mail accounts are configured for failover.'
            : 'Every mail account failed — '.implode(' | ', $parts));
    }

    /** @return array<string, TransportExceptionInterface> */
    public function failures(): array
    {
        return $this->failures;
    }
}
