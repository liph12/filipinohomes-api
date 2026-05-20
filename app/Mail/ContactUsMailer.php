<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Dedicated mailer for submissions from the public Contact Us page. The form
 * collects more fields than the generic InquiryMailer (phone, inquiry type,
 * subject) so it has its own blade template too — contact-us.blade.php.
 */
class ContactUsMailer extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $clientAvatar = null;

    public function __construct(
        public string  $clientName,
        public string  $clientEmail,
        public string  $clientMessage,
        public ?string $clientPhone   = null,
        public ?string $inquiryType   = null,
        public ?string $clientSubject = null,
    ) {
        $this->clientAvatar = User::where('email', $clientEmail)->value('avatar') ?: null;
    }

    public function envelope(): Envelope
    {
        $subjectLine = $this->clientSubject
            ? 'Contact Us: ' . $this->clientSubject
            : 'New Contact Us Submission from ' . $this->clientName;

        return new Envelope(
            subject: $subjectLine,
            replyTo: [
                new Address($this->clientEmail, $this->clientName),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-us',
        );
    }
}
