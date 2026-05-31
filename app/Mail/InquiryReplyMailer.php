<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use App\Models\Inquiry;
use App\Models\InquiryReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Outbound reply from an admin to a client who submitted an inquiry through
 * Get In Touch / Contact Us. From: header is info@filipinohomes.com (not the
 * individual admin) so future client replies thread back to the shared inbox
 * and any admin can pick them up.
 */
class InquiryReplyMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public Inquiry      $inquiry,
        public InquiryReply $reply,
        public string       $adminName,
        public ?string      $adminAvatar = null,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        $subject = $this->reply->subject ?: 'Re: Filipino Homes — your message';
        $from    = env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com');

        return new Envelope(
            from:    new Address($from, 'Filipino Homes'),
            subject: $subject,
            replyTo: [new Address($from, 'Filipino Homes')],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.inquiry-reply');
    }
}
