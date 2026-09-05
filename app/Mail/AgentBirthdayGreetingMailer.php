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
 * Personal birthday greeting sent TO the agent on their birthday (the admin
 * digest StaffBirthdaysMailer is a different email).
 *
 * $posterUrl is the composited poster (photo + name) hosted on S3, shown in
 * the body and linked as the "Save your poster" button — the S3 object carries
 * a Content-Disposition header, so that link downloads rather than opening a
 * tab. Null hides the whole block, so the greeting still goes out if rendering
 * failed.
 *
 * The poster is deliberately NOT attached. It was, and the message then
 * carried the same image twice: once inline and once as a file, which Gmail
 * renders as a second copy under an "One attachment" bar. Showing it once and
 * offering the download is the same capability without the duplicate.
 */
class AgentBirthdayGreetingMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public string $firstName,
        /** First + last, no middle name. */
        public string $fullName,
        public ?string $posterUrl,
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
            // Sent as the company; signed by Anthony inside the letter. The
            // ADDRESS stays info@filipinohomes.com — that is the authenticated
            // sender, and login OTPs ride the same reputation.
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), 'Filipino Homes'),
            subject: $this->subjectPrefix
                ? "{$this->subjectPrefix} 🎂 Happy Birthday, {$this->firstName}!"
                : "🎂 Happy Birthday, {$this->firstName}!",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.agent-birthday-greeting');
    }
}
