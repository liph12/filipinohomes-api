<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use App\Services\Birthday\BirthdayPosterService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Personal birthday greeting sent TO the agent on their birthday (the admin
 * digest StaffBirthdaysMailer is a different email). $posterUrl is the
 * composited poster (photo + name) hosted on S3; null hides the image block
 * so the greeting still goes out if rendering failed. $posterJpeg, when
 * given, is also attached to the message so the agent can save the poster
 * straight from their mail client (the S3 link downloads too, via its
 * Content-Disposition header).
 */
class AgentBirthdayGreetingMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public string $firstName,
        /** First + last, no middle name. */
        public string $fullName,
        public ?string $posterUrl,
        /** Raw JPEG bytes; attached as a file when present. */
        public ?string $posterJpeg = null,
        /**
         * Set in whitelist mode to name the real recipient. A constructor
         * property rather than a ->subject() call, because Mailable::subject()
         * is IGNORED whenever the class defines envelope() — setting it there
         * fails silently.
         */
        public ?string $subjectPrefix = null,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            // The NAME is Anthony because the letter is signed by him and a
            // personal note arriving from a system address reads as a mailshot.
            // The ADDRESS stays info@filipinohomes.com — that is the
            // authenticated sender, and login OTPs ride the same reputation.
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), 'Anthony Leuterio · Filipino Homes'),
            subject: $this->subjectPrefix
                ? "{$this->subjectPrefix} 🎂 Happy Birthday, {$this->firstName}!"
                : "🎂 Happy Birthday, {$this->firstName}!",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-birthday-greeting');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if ($this->posterJpeg === null) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->posterJpeg, BirthdayPosterService::downloadFilename($this->fullName))
                ->withMime('image/jpeg'),
        ];
    }
}
