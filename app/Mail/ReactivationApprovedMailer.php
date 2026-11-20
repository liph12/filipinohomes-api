<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a deactivated agent when an admin accepts their reactivation request:
 * the account works again and they can log in.
 */
class ReactivationApprovedMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public string $agentName,
        public string $loginUrl,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: 'Your Filipino Homes account is active again',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reactivation-approved',
        );
    }
}
