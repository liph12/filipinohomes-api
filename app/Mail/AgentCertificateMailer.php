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
 * Emails a Top-10 leaderboard certificate (rendered client-side as a PNG and
 * uploaded by the admin) to the awarded agent as an attachment. Sent
 * synchronously from AgentController@sendCertificate, mirroring the audit
 * verification mailers (ListingFlaggedMailer / ListingVerifiedMailer).
 */
class AgentCertificateMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public string $agentName,
        public string $awardMonth,   // e.g. "June"
        public int    $awardYear,    // e.g. 2026
        // Image bytes + filename of the generated certificate.
        public string $certificateData,
        public string $certificateFilename,
        // MIME of the attached image (image/jpeg after server-side normalize,
        // image/png if normalization was skipped). Drives Gmail's inline
        // thumbnail preview.
        public string $certificateMime = 'image/png',
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'), env('MAIL_FROM_NAME', 'Filipinohomes')),
            subject: "Congratulations! Your {$this->awardMonth} {$this->awardYear} Top Agent Certificate",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.agent-certificate',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->certificateData, $this->certificateFilename)
                ->withMime($this->certificateMime),
        ];
    }
}
