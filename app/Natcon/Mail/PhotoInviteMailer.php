<?php

namespace App\Natcon\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * NATCON 2026 photo-confirmation outreach.
 *
 * Two modes, one template — the same shape AtsExpiryMailer already uses:
 *   'invite'   — first contact
 *   'reminder' — the Aug 20 / 21 / 22 nudges, urgent theme
 *
 * ─── The countdown is frozen at send time ────────────────────────────────────
 * Email cannot tick. $daysRemaining is whatever it was when the message was
 * rendered, so it is ALWAYS printed next to $deadlineLabel — the absolute date is
 * never wrong, the relative number can only go stale downward, and putting them
 * side by side makes a stale number self-correcting for the reader. Animated-GIF
 * countdown services are deliberately not used: they're third-party tracking
 * pixels, Gmail's image proxy caches the first frame, and they leak the
 * recipient's address to a vendor.
 */
class PhotoInviteMailer extends Mailable
{
    use Queueable, SerializesModels, TagsFhMailerHeader;

    public function __construct(
        /** 'invite' | 'reminder' */
        public string  $mode,
        public string  $recipientName,
        public ?string $team,
        /** @var array<int,string> Deduped LR photos. Empty triggers the no-photo variant. */
        public array   $photos,
        public string  $retainUrl,
        public string  $changeUrl,
        /** Absolute, always correct — e.g. "August 24, 2026". */
        public string  $deadlineLabel,
        /**
         * Just the ordinal day, e.g. "24th".
         *
         * The invite copy the team wrote reads "our deadline for collection will
         * be on the 24th", so the phrasing needs the bare day. Passing it keeps
         * their wording intact for any year instead of freezing 2026 into the
         * template.
         */
        public string  $deadlineDay,
        /** Whole days in Asia/Manila, floored at 0. Frozen at send. */
        public int     $daysRemaining,
        /** e.g. "NATCON 2026" — from the event row, so 2027 needs no code change. */
        public string  $eventName,
        public string  $eventDates,
        public string  $eventVenue,
        public string  $bannerUrl,
        public ?string $unsubscribeUrl = null,
        public ?int    $reminderIndex = null,
        /**
         * Prepended to the subject in whitelist mode so a QA pass can tell whose
         * message is whose when everything is redirected to one inbox.
         *
         * It's a constructor argument rather than a ->subject() call because
         * Mailable::subject() is IGNORED whenever the class defines envelope() —
         * setting it there fails silently.
         */
        public ?string $subjectPrefix = null,
    ) {
        // Must be called here — Mailable has no boot hook, and without it every
        // send audits as source: 'unknown' and can't be attributed in the
        // activity-log feed.
        $this->tagFhMailerHeader();
    }

    /** True when we have nothing on file and the copy has to ask for a photo instead. */
    public function hasPhotos(): bool
    {
        return count($this->photos) > 0;
    }

    public function envelope(): Envelope
    {
        $event = $this->eventName;

        $subject = match (true) {
            $this->mode === 'invite'      => "Your {$event} photo — keep it or send a new one",
            $this->daysRemaining <= 0     => "Today is the deadline — confirm your {$event} photo",
            $this->daysRemaining === 1    => "Last day tomorrow — confirm your {$event} photo",
            default                       => "Reminder: {$this->daysRemaining} days left to confirm your {$event} photo",
        };

        // env() with non-null defaults mirrors AtsExpiryMailer exactly. After
        // `php artisan config:cache` env() returns null outside config files, so
        // the fallbacks are load-bearing — do not "clean them up".
        return new Envelope(
            from: new Address(
                env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'),
                env('MAIL_FROM_NAME', 'Filipinohomes'),
            ),
            subject: $this->subjectPrefix ? $this->subjectPrefix . ' ' . $subject : $subject,
        );
    }

    /**
     * List-Unsubscribe + one-click POST.
     *
     * This is a campaign send rather than a transactional one, and this domain has
     * no bulk-sending history. Gmail and Yahoo's bulk-sender rules expect these
     * headers, and in practice they're the single biggest complaint-rate reducer:
     * they turn "mark as spam" — which damages the sending domain and would
     * eventually take login OTPs down with it — into a quiet unsubscribe.
     */
    public function headers(): Headers
    {
        if (! $this->unsubscribeUrl) {
            return new Headers();
        }

        return new Headers(text: [
            'List-Unsubscribe'      => '<' . $this->unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.natcon-photo-invite');
    }
}
