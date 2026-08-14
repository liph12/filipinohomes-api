<?php

namespace App\Natcon\Mail;

use App\Mail\Concerns\TagsFhMailerHeader;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
        /**
         * A reviewer has ruled the photo on file unusable for print.
         *
         * Changes the message rather than just the buttons: offering "keep the
         * one we have" to somebody we have already decided against is the ask
         * that generates a reply, a phone call, and no photo.
         */
        public bool    $requiresNewPhoto = false,
        /** Minimum that completes a submission. From config, so the organizers
         *  can move it without a release. */
        public int     $requiredCount = 1,
        /** Ceiling. Needed because the ask is a RANGE whenever the two differ —
         *  "send 1-3 photos" cannot be phrased from requiredCount alone. */
        public int     $maxCount = 1,
        /** How many they have already sent — drives the partial reminder. */
        public int     $uploadedCount = 0,
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

        // A flagged awardee has no choice to make, and a partial submitter has
        // already started — telling either of them to "keep it or send a new one"
        // is the kind of wrong subject line that gets the next one ignored.
        $partial    = $this->uploadedCount > 0 && $this->uploadedCount < $this->requiredCount;
        $short      = $this->requiredCount - $this->uploadedCount;
        $shortLabel = $short . ' more ' . ($short === 1 ? 'photo' : 'photos');

        $subject = match (true) {
            $this->mode === 'invite' && $this->requiresNewPhoto
                => "We need a new photo for {$event}",
            $this->mode === 'invite' && ! $this->hasPhotos()
                => "We need your {$event} photo",
            $this->mode === 'invite'
                => "Your {$event} photo — keep it or send a new one",
            $partial && $this->daysRemaining <= 0
                => "Today is the deadline — {$shortLabel} needed for {$event}",
            $partial
                => "Almost there — {$shortLabel} for {$event}",
            // A flagged awardee has nothing to "confirm". Reusing the confirm
            // wording tells them to do something the page will not let them do.
            $this->requiresNewPhoto && $this->daysRemaining <= 0
                => "Today is the deadline — we still need a new photo for {$event}",
            $this->requiresNewPhoto
                => "We still need a new photo for {$event}",
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

    // NOTE: no headers() override, and no List-Unsubscribe header.
    //
    // Gmail renders its own Unsubscribe button whenever that header is present,
    // so advertising it while /natcon/unsubscribe returns 404 is worse than
    // omitting it — the recipient clicks, nothing happens, and the next step is
    // "Report spam", which is exactly what the header exists to prevent.
    //
    // Below Gmail's ~5,000/day bulk threshold this list doesn't require one.
    // If the campaign ever crosses it: build the page first, then add the
    // header and the footer link together.

    public function content(): Content
    {
        return new Content(view: 'emails.natcon-photo-invite');
    }
}
