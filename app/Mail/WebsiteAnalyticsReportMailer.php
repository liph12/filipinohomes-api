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
 * The daily Website Analytics (GA4) email: yesterday's traffic, sources,
 * pages, geography, lead actions, month-to-date totals, and the optional AI
 * executive summary. $report is WebsiteAnalyticsReportService::build()'s
 * array. Recipients ride as BCC behind info@filipinohomes.com (the standing
 * admin-email rule); preview at /preview/email/website-analytics.
 */
class WebsiteAnalyticsReportMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(public array $report)
    {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: 'Filipino Homes Website Analytics — '.$this->report['date_label'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.website-analytics-report');
    }
}
