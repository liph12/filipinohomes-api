<?php

namespace App\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use App\Models\Agent;
use App\Models\ClientBrief;
use App\Support\Briefs;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the agent, one per brief sent through their Buyers & Sellers Link:
 * who the client is, every answer in the client's four steps, and buttons
 * to open it on the dashboard or reply on WhatsApp / Viber.
 */
class BriefReceivedMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        public ClientBrief $brief,
        public Agent $agent,
    ) {
        $this->tagFhMailerHeader();
    }

    public function envelope(): Envelope
    {
        $what = $this->brief->kind === 'seller' ? 'New seller brief' : 'New buyer brief';

        return new Envelope(
            subject: $what.': '.$this->brief->name,
            replyTo: [new Address($this->brief->email, $this->brief->name)],
        );
    }

    public function content(): Content
    {
        $brief = $this->brief;
        $site = Briefs::siteUrl();
        $view = $brief->kind === 'seller' ? 'sellers' : 'buyers';
        $grade = $brief->kind === 'buyer' ? Briefs::grade($brief->budget, $brief->profile ?? []) : null;

        return new Content(
            view: 'emails.brief-received',
            with: [
                'agentName' => Briefs::agentName($this->agent),
                'kind' => $brief->kind,
                'clientName' => $brief->name,
                'clientFirst' => explode(' ', trim($brief->name))[0] ?: $brief->name,
                'sections' => Briefs::sections($brief),
                'gradeLabel' => $grade ? config("briefs.grade.labels.$grade.label") : null,
                'gradeWhy' => $grade ? config("briefs.grade.labels.$grade.why") : null,
                'dashboardUrl' => "{$site}/agent/buyers-link?view={$view}&brief={$brief->id}",
                'whatsappUrl' => ($d = Briefs::phoneDigits($brief->phone_code, $brief->phone)) !== '' ? "https://wa.me/{$d}" : null,
                'viberUrl' => $d !== '' ? "viber://chat?number=%2B{$d}" : null,
                'receivedAt' => $brief->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
            ],
        );
    }
}
