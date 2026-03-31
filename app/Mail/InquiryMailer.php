<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InquiryMailer extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $clientName,
        public string $clientEmail,
        public string $clientMessage,
        public array $ccRecipients = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Inquiry from ' . $this->clientName,
            replyTo: [
                new Address($this->clientEmail, $this->clientName),
            ],
            cc: array_map(
                fn($cc) => new Address($cc['email'], $cc['name'] ?? ''),
                $this->ccRecipients,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.inquiry',
        );
    }
}
// namespace App\Mail;

// use Illuminate\Bus\Queueable;
// use Illuminate\Mail\Mailable;
// use Illuminate\Mail\Mailables\Content;
// use Illuminate\Mail\Mailables\Envelope;
// use Illuminate\Queue\SerializesModels;

// class InquiryMailer extends Mailable
// {
//     use Queueable, SerializesModels;

//     public function __construct(
//         public string $clientName,
//         public string $clientEmail,
//         public string $clientMessage,
//     ) {}

//     public function envelope(): Envelope
//     {
//         return new Envelope(
//             subject: 'New Inquiry from ' . $this->clientName,
//             replyTo: [$this->clientEmail], // Admin can reply directly to client
//         );
//     }

//     public function content(): Content
//     {
//         return new Content(
//             view: 'emails.inquiry',
//         );
//     }
// }