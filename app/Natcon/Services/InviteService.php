<?php

namespace App\Natcon\Services;

use App\Natcon\Exceptions\ExpiredLinkException;
use App\Natcon\Exceptions\InvalidLinkException;
use App\Natcon\Mail\PhotoInviteMailer;
use App\Natcon\Models\NatconEvent;
use App\Natcon\Models\Outbox;
use App\Natcon\Models\Recipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invite links and send claims.
 *
 * ─── Why a DB-backed token and not URL::signedRoute ──────────────────────────
 *
 *   1. signedRoute signs a URL on api2, but the emailed CTA has to land on
 *      filipinohomes.com. Making it work means linking to api2 and 302-ing, which
 *      adds a hop and puts a second domain in the email body — a real spam-filter
 *      penalty on a campaign send.
 *   2. Its signatures derive from APP_KEY. Rotate the key, or run staging with a
 *      different one, and every outstanding link dies at once, mid-campaign.
 *   3. A stateless signature can't be revoked before expiry. A DB row is one
 *      UPDATE.
 *   4. We have to load the recipient anyway — their LR snapshot lives with us —
 *      so making the token BE the lookup key costs nothing.
 *
 * ─── Why the token is DERIVED rather than random ────────────────────────────
 *
 * The obvious design — random string, store its hash, email the raw — has a nasty
 * edge here: we'd only hold the hash, so the raw could never be recovered, and
 * every reminder send would have to mint a new token and silently kill the link in
 * the invite we sent a week earlier. People absolutely do scroll back and click
 * the first email; that would 404 them at exactly the moment we're chasing them.
 *
 * So the token is derived from the row instead:
 *
 *     raw = "{id}.{hmac_sha256(id:event_id:token_nonce, NATCON_LINK_SECRET)}"
 *
 *   - Reproducible: a resend regenerates the identical link, so every email we've
 *     ever sent this person keeps working.
 *   - Not stored: the database holds an id and a nonce; without the secret (which
 *     lives in the environment, not the DB) neither a backup nor a read replica
 *     yields a usable link.
 *   - Revocable: a new nonce rotates every link for that recipient.
 *   - Cheap to verify: the id prefix means a primary-key lookup, then one
 *     constant-time hash_equals.
 *
 * The nonce is the rotation lever specifically because token_issued_at is not one
 * — it's a second-granular timestamp, so re-minting twice inside the same second
 * reproduced the identical token and "issue a new link" silently failed to revoke
 * anything.
 */
final class InviteService
{
    /**
     * Rotate this recipient's link and return the new raw token. Any previously
     * emailed link stops working — that's the point, so this is only for the
     * admin's explicit "issue a new link" action, never for a routine resend.
     */
    public function mintToken(Recipient $recipient): string
    {
        $expiry = $recipient->event?->photo_deadline_at
            ?->copy()
            ->addDays((int) config('natcon.token_grace_days', 14));

        $recipient->forceFill([
            // New nonce == unconditional rotation. Never derive this from a clock:
            // token_issued_at is second-granular, so two mints in the same second
            // would produce the same token and revoke nothing.
            'token_nonce'      => bin2hex(random_bytes(16)),
            'token_issued_at'  => Carbon::now(),
            // Grace period past the deadline on purpose: a hard expiry at the
            // deadline turns every late click into a dead link and a support call.
            // An aged token still resolves, and the page shows "collection has
            // closed" — a far better answer than a 404.
            'token_expires_at' => $expiry,
        ])->save();

        $raw = $this->rawFor($recipient);

        // Stored so the admin list can be filtered/joined on it and so the unique
        // index catches any collision. It is the HMAC, never the raw token.
        $recipient->forceFill(['invite_token_hash' => $this->hash($raw)])->save();

        return $raw;
    }

    /**
     * The recipient's current token, minting one only if they've never had one.
     * This is what the send paths use: a reminder reproduces the same link the
     * invite carried, so both emails keep working.
     */
    public function ensureToken(Recipient $recipient): string
    {
        if (! $recipient->token_issued_at || ! $recipient->token_nonce) {
            return $this->mintToken($recipient);
        }

        $raw = $this->rawFor($recipient);

        // Backfill for rows that predate the hash column being populated.
        if (! $recipient->invite_token_hash) {
            $recipient->forceFill(['invite_token_hash' => $this->hash($raw)])->save();
        }

        return $raw;
    }

    /**
     * @throws InvalidLinkException|ExpiredLinkException
     */
    public function resolveToken(string $raw): Recipient
    {
        $raw = trim($raw);

        if ($raw === '' || mb_strlen($raw) < 32 || mb_strlen($raw) > 160 || ! str_contains($raw, '.')) {
            throw new InvalidLinkException();
        }

        [$id, $signature] = explode('.', $raw, 2);

        if (! ctype_digit($id) || $signature === '') {
            throw new InvalidLinkException();
        }

        $recipient = Recipient::with('event')->find((int) $id);

        // ⚠️ Deliberately does NOT also filter on the `email` query param. The
        // emailed URL carries ?email= for display, and mail clients mangle query
        // strings; matching on it would turn cosmetic damage into a false 404.
        // The token alone identifies the recipient.
        if (! $recipient || ! $recipient->event || ! $recipient->token_nonce) {
            throw new InvalidLinkException();
        }

        // Constant-time. A mismatch means a forged or rotated token.
        if (! hash_equals($this->rawFor($recipient), $raw)) {
            throw new InvalidLinkException();
        }

        if ($recipient->status === Recipient::STATUS_EXCLUDED) {
            throw new InvalidLinkException();
        }

        if (! $recipient->event->is_active) {
            throw new InvalidLinkException();
        }

        if ($recipient->token_expires_at && $recipient->token_expires_at->isPast()) {
            throw new ExpiredLinkException();
        }

        return $recipient;
    }

    /**
     * The URL that goes in the email.
     *
     * `email` is decorative — it lets the page paint a name before the API round
     * trip and it matches the URL shape the team specified. `intent` preselects a
     * button. Neither is trusted server-side.
     */
    public function buildLink(Recipient $recipient, string $rawToken, ?string $intent = null): string
    {
        $base = rtrim(
            $recipient->event?->update_profile_url
                ?: 'https://filipinohomes.com/natcon/update-profile',
            '/',
        );

        $query = ['email' => $recipient->email];

        if (in_array($intent, [Recipient::RESPONSE_RETAIN, Recipient::RESPONSE_CHANGE], true)) {
            $query['intent'] = $intent;
        }

        $query['t'] = $rawToken;

        return $base . '?' . http_build_query($query);
    }

    /**
     * Claim the right to send one message to this recipient today.
     *
     * ★ This is the never-double-send guarantee, and it is enforced by MySQL
     *   rather than by code discipline. natcon_outbox has UNIQUE(recipient, kind,
     *   send_date); insertOrIgnore means a losing race is a no-op, not an
     *   exception. Returns null when the claim was already taken.
     *
     *   That single fact covers: an admin double-clicking Send, the scheduler
     *   firing twice, the frontend axios interceptor transparently replaying a
     *   POST after a 401, and a drain retry.
     */
    public function claimSend(
        Recipient $recipient,
        string $kind,
        ?Carbon $date = null,
        ?string $batchId = null,
        ?int $reminderIndex = null,
        ?int $requestedBy = null,
    ): ?Outbox {
        $tz   = $recipient->event?->timezone ?: 'Asia/Manila';
        $day  = ($date ?: Carbon::now($tz))->copy()->setTimezone($tz)->toDateString();
        $now  = Carbon::now();

        // ⚠️ Raw insert — a key omitted here is a silent NULL, not an error.
        //    `requested_by` is nullable because only one of this method's three
        //    callers has an authenticated user: the admin batch does, while the
        //    public resend-link endpoint and the reminder cron do not.
        $inserted = DB::table('natcon_outbox')->insertOrIgnore([
            'natcon_recipient_id' => $recipient->id,
            'natcon_event_id'     => $recipient->natcon_event_id,
            'kind'                => $kind,
            'send_date'           => $day,
            'reminder_index'      => $reminderIndex,
            'batch_id'            => $batchId,
            'requested_by'        => $requestedBy,
            'status'              => Outbox::STATUS_QUEUED,
            'attempts'            => 0,
            'queued_at'           => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        if ($inserted === 0) {
            return null;   // already claimed today — skip, don't resend
        }

        return Outbox::where('natcon_recipient_id', $recipient->id)
            ->where('kind', $kind)
            ->where('send_date', $day)
            ->first();
    }

    // NOTE on `attempts`: it is incremented once, by DrainOutbox::claim(), at the
    // moment a row is taken off the queue. Neither method below touches it —
    // incrementing here too would double-count and burn the retry budget in half
    // the runs it should take.

    public function markSent(Outbox $send, string $subject): void
    {
        $send->forceFill([
            'status'  => Outbox::STATUS_SENT,
            'subject' => mb_substr($subject, 0, 255),
            'sent_at' => Carbon::now(),
            'error'   => null,
        ])->save();
    }

    public function markFailed(Outbox $send, \Throwable $e): void
    {
        $max = (int) config('natcon.max_attempts', 3);

        $send->forceFill([
            // Back to 'queued' while retries remain so the next drain tick picks
            // it up; only settle on 'failed' once we've genuinely given up.
            // Returning it to 'queued' also releases the claim.
            'status'    => $send->attempts >= $max ? Outbox::STATUS_FAILED : Outbox::STATUS_QUEUED,
            'error'     => mb_substr($e->getMessage(), 0, 500),
            'failed_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Build the message for one recipient.
     *
     * Both CTA links point at the same page with different `intent` values — the
     * intent only preselects a button there, it never commits. That is not a
     * stylistic choice: Outlook SafeLinks, Mimecast and Proofpoint GET every URL
     * in an email within seconds of delivery, so a link that acted on GET would
     * record hundreds of "responses" from scanners and then exclude those people
     * from the reminders meant to reach them.
     */
    public function buildMailable(Recipient $recipient, string $kind): PhotoInviteMailer
    {
        $event = $recipient->event;
        $token = $this->ensureToken($recipient);
        $tz    = $event->timezone ?: 'Asia/Manila';

        $deadline = $event->deadlineLocal();

        return new PhotoInviteMailer(
            mode:           in_array($kind, Outbox::INVITE_LIKE, true) ? 'invite' : 'reminder',
            recipientName:  $recipient->displayName(),
            team:           $recipient->team,
            photos:         $recipient->displayPhotos(),
            retainUrl:      $this->buildLink($recipient, $token, Recipient::RESPONSE_RETAIN),
            changeUrl:      $this->buildLink($recipient, $token, Recipient::RESPONSE_CHANGE),
            deadlineLabel:  $deadline ? $deadline->format('F j, Y') : 'the deadline',
            deadlineDay:    $deadline ? $deadline->format('jS') : 'deadline day',
            // Frozen at render time. Floored at 0 so a late send never prints a
            // negative day count.
            daysRemaining:  max(0, (int) ($event->daysUntilDeadline() ?? 0)),
            eventName:      $event->displayShortName(),
            eventDates:     $event->dateLabel(),
            eventVenue:     $event->venue,
            // Per-event, not per-deployment: a config default would silently
            // give next year's email this year's banner.
            bannerUrl:      $event->email_banner_url ?: (string) config('natcon.email.banner_url'),
        );
    }

    /**
     * Recipients still eligible for a reminder: invited or already reminded, and
     * not yet responded. Index-backed by (natcon_event_id, status).
     */
    public function reminderTargets(NatconEvent $event)
    {
        return Recipient::query()
            ->where('natcon_event_id', $event->id)
            ->whereIn('status', Recipient::REMINDABLE)
            ->whereNull('responded_at')
            ->whereNotNull('invite_token_hash');
    }

    /**
     * Rebuild the recipient's raw token from their row. Deterministic, so the same
     * row always yields the same link until token_issued_at changes.
     */
    private function rawFor(Recipient $recipient): string
    {
        $material = implode(':', [
            $recipient->id,
            $recipient->natcon_event_id,
            (string) $recipient->token_nonce,
        ]);

        return $recipient->id . '.' . hash_hmac('sha256', $material, $this->secret());
    }

    private function hash(string $raw): string
    {
        return hash_hmac('sha256', $raw, $this->secret());
    }

    private function secret(): string
    {
        // config(), never env(): after `php artisan config:cache`, env() returns
        // null outside config files. That exact mistake 401'd every /listings
        // request in production once — see VerifyGuestToken's docblock.
        $secret = (string) config('natcon.link_secret');

        if ($secret === '') {
            // Failing loudly beats deriving tokens under an empty key and silently
            // shipping links anyone can forge.
            throw new \RuntimeException(
                'NATCON_LINK_SECRET is not configured. Generate one with '
                . "php -r 'echo bin2hex(random_bytes(32));' and set it in .env."
            );
        }

        return $secret;
    }
}
