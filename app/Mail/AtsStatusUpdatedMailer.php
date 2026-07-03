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
 * Sent to a listing's agent whenever an admin changes its ATS (Authority to
 * Sell) status — approved / pending / expired / rejected — so the agent knows
 * the outcome and can act (e.g. re-upload documents when expired/rejected).
 */
class AtsStatusUpdatedMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public string  $agentName,
        public string  $listingTitle,
        public string  $listingCode,
        /** Display label: Approved / Pending / Expired / Rejected. */
        public string  $atsStatus,
        public ?string $atsRemarks,
        /** Formatted expiration date (e.g. "December 25, 2026") or null. */
        public ?string $atsExpiration,
        public string  $listingUrl,
        /** Listing's featured photo URL (shown at the left of the listing card). */
        public ?string $featuredPhoto = null,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: 'ATS Status Updated — ' . $this->listingCode,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ats-status-updated',
        );
    }
}
