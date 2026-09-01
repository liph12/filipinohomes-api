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
 * The boss-facing site activity digest: audience size, traffic sources,
 * PH-only geography, created listings, new projects, and inquiry flow for one
 * date window. $report is AdminActivityReportService::build()'s array.
 *
 * Preview-only for now — nothing sends this yet; /preview/email/boss-report
 * renders it with live local data. Wiring a schedule + recipient comes later.
 */
class AdminActivityReportMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public array $report,
        public string $periodLabel,
        public string $recipientName = 'Boss',
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: 'Filipino Homes Activity Report — '.$this->periodLabel,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-activity-report');
    }
}
