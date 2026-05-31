<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InquiryMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    /**
     * Avatar URL for the submitter, if they're a registered Filipino Homes
     * user (lookup by email at construction time). Null for first-time
     * leads — the blade falls back to a ui-avatars.com initials thumbnail.
     */
    public ?string $clientAvatar = null;

    public function __construct(
        public string $clientName,
        public string $clientEmail,
        public string $clientMessage,
        public ?string $source = null,
    ) {
        // Best-effort avatar lookup so repeat clients show up with a face
        // instead of just initials. One indexed query on a unique-ish column;
        // safe to skip silently when no row exists.
        $this->clientAvatar = User::where('email', $clientEmail)->value('avatar') ?: null;
        $this->tagFhMailerHeader();
    }

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

        // No CC by design: admin fan-out happens via BCC at the call-site
        // (UserController::sendInquiry) so admins never see each other's
        // addresses in the header. Don't add `cc:` here.
        return new Envelope(
            subject: $subject,
            replyTo: [
                new Address($this->clientEmail, $this->clientName),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inquiry',
        );
    }
}