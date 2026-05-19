<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InquiryMailer extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $clientName,
        public string $clientEmail,
        public string $clientMessage,
        public array $ccRecipients = [],
        // Source key submitted from the frontend ('home_get_in_touch',
        // 'contact_page'). Rendered as a "Submitted from:" indicator in the
        // blade so admins can tell which page the message came from. Optional
        // for backwards compatibility with older code paths.
        public ?string $source = null,
    ) {}

    public function envelope(): Envelope
    {
        // Subject reads like "Get In Touch: Maria Santos" — consistent with
        // ContactUsMailer's "Contact Us: …" pattern so admins can triage by
        // origin at a glance. Maintenance page submissions get a clarifying
        // suffix so they're distinguishable from home-page Get-in-Touch ones.
        $subject = match ($this->source) {
            'maintenance_page'  => 'Contact Us (Maintenance): ' . $this->clientName,
            'home_get_in_touch' => 'Get In Touch: ' . $this->clientName,
            default             => 'Get In Touch: ' . $this->clientName,
        };

        return new Envelope(
            subject: $subject,
            replyTo: [
                new Address($this->clientEmail, $this->clientName),
            ],
            cc: array_map(
                fn($cc) => new Address($cc['email'], $cc['name'] ?? ''),
                $this->ccRecipients,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inquiry',
        );
    }
}