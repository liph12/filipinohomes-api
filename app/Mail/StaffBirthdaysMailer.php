<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily staff-birthdays digest: today's celebrants (section hidden when
 * none) and the next 30 days. $birthdays is StaffBirthdaysService::build().
 *
 * $posters — today's rendered birthday posters, each
 * ['name','url','jpeg','filename']: shown inline (linking to the S3 file,
 * which downloads via Content-Disposition) AND attached, capped at
 * MAX_POSTERS so a busy day can't produce a multi-megabyte email.
 */
class StaffBirthdaysMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public const MAX_POSTERS = 8;

    public function __construct(
        public array $birthdays,
        public string $dateLabel,
        public string $recipientName = 'Boss',
        /** @var array<int, array{name:string, url:string, jpeg:?string, filename:string}> */
        public array $posters = [],
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
                : 'Birthdays — ').$this->dateLabel,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.staff-birthdays');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $out = [];
        foreach (array_slice($this->posters, 0, self::MAX_POSTERS) as $p) {
            if (empty($p['jpeg'])) {
                continue;
            }
            $out[] = Attachment::fromData(fn () => $p['jpeg'], $p['filename'])->withMime('image/jpeg');
        }

        return $out;
    }
}
