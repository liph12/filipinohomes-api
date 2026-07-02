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
 * Sent to a listing's agent when its ATS (Authority to Sell) is about to expire
 * (7 days out) or has already expired, so they can renew/re-upload in time.
 *
 * $mode: 'soon' (expiring in ~1 week) or 'expired' (already lapsed).
 */
class AtsExpiryMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public string  $mode,
        public string  $agentName,
        public string  $listingTitle,
        public string  $listingCode,
        /** Formatted expiration date (e.g. "December 25, 2026"). */
        public ?string $atsExpiration,
        public ?string $atsRemarks,
        public string  $listingUrl,
        public ?string $featuredPhoto = null,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        $subject = $this->mode === 'expired'
            ? 'Your ATS Has Expired — ' . $this->listingCode
            : 'Your ATS Expires Soon — ' . $this->listingCode;

        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ats-expiry',
        );
    }
}
