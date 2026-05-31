<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Listing;

class ListingFlaggedMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public string  $agentName,
        public string  $listingTitle,
        public string  $listingCode,
        public string  $auditNotes,
        public ?array  $auditChecklist,
        public string  $listingUrl,
        public ?array  $editedFields = null,
        // True when property type is Land — blade hides the amenities row in
        // the checklist (Land listings have no amenities to verify).
        public bool    $isLand = false,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: 'Action Required: Your Listing Has Been Flagged — ' . $this->listingCode,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.listing-flagged',
        );
    }
}
