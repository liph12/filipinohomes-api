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
 * The daily staff-birthdays digest: today's celebrants (section hidden when
 * none) and the next 30 days. $birthdays is StaffBirthdaysService::build().
 *
 * $posters — EVERY celebrant's poster today, each ['name','url','preview_url']:
 * the small preview shown inline, linking to the full S3 file (which downloads
 * via Content-Disposition). Not attached: at ~355 KB a poster, 8 attachments
 * made a ~3.8 MB email repeating what the inline images already offer.
 */
class StaffBirthdaysMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public array $birthdays,
        public string $dateLabel,
        public string $recipientName = 'Boss',
        /** @var array<int, array{name:string, url:string, preview_url:?string}> */
        public array $posters = [],
        /** Appended to the subject (test sends only) so repeats don't thread in Gmail. */
        public ?string $subjectSuffix = null,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        $todayCount = count($this->birthdays['today'] ?? []);

        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: ($todayCount > 0
                ? "🎂 {$todayCount} Birthday".($todayCount === 1 ? '' : 's').' Today — '
                : 'Birthdays — ').$this->dateLabel.($this->subjectSuffix ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.staff-birthdays');
    }
}
