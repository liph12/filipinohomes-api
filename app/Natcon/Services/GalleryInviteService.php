<?php

namespace App\Natcon\Services;

use App\Natcon\Exceptions\ExpiredLinkException;
use App\Natcon\Exceptions\InvalidLinkException;
use App\Natcon\Models\GalleryUploadInvite;
use App\Natcon\Models\NatconEvent;
use Illuminate\Support\Carbon;

/**
 * Photographer upload-invite links.
 *
 * The token design is InviteService's, copied deliberately — read the long
 * docblock there for why the token is DERIVED from the row rather than
 * random (resend-stable links, nothing usable in a DB backup, one-UPDATE
 * revocation via the nonce) and why it is not URL::signedRoute.
 *
 * The one difference: rawFor() prefixes the HMAC material with a domain
 * string ('gallery-invite'), so an upload-invite token can never verify as
 * an awardee Recipient token even in a pathological id+nonce collision —
 * the two token families share natcon.link_secret.
 */
final class GalleryInviteService
{
    /**
     * Rotate this invite's link and return the new raw token. Every
     * previously shared link stops working — that is the point; the admin's
     * "Copy link" uses ensureToken() instead.
     */
    public function mintToken(GalleryUploadInvite $invite): string
    {
        $invite->forceFill([
            // New nonce == unconditional rotation. Never derive from a clock:
            // token_issued_at is second-granular, so two mints in the same
            // second would produce the same token and revoke nothing.
            'token_nonce' => bin2hex(random_bytes(16)),
            'token_issued_at' => Carbon::now(),
            'token_expires_at' => $this->defaultExpiry($invite->event),
        ])->save();

        $raw = $this->rawFor($invite);

        // Stored so the unique index catches any collision. It is the HMAC of
        // the raw token, never the raw itself.
        $invite->forceFill(['invite_token_hash' => $this->hash($raw)])->save();

        return $raw;
    }

    /**
     * The invite's current token, minting one only if it never had one. This
     * powers the admin's "Copy link": handing the same photographer the link
     * twice must not kill the copy they already have.
     */
    public function ensureToken(GalleryUploadInvite $invite): string
    {
        if (! $invite->token_issued_at || ! $invite->token_nonce) {
            return $this->mintToken($invite);
        }

        $raw = $this->rawFor($invite);

        if (! $invite->invite_token_hash) {
            $invite->forceFill(['invite_token_hash' => $this->hash($raw)])->save();
        }

        return $raw;
    }

    /**
     * @throws InvalidLinkException|ExpiredLinkException
     */
    public function resolveToken(string $raw): GalleryUploadInvite
    {
        $raw = trim($raw);

        if ($raw === '' || mb_strlen($raw) < 32 || mb_strlen($raw) > 160 || ! str_contains($raw, '.')) {
            throw new InvalidLinkException;
        }

        [$id, $signature] = explode('.', $raw, 2);

        if (! ctype_digit($id) || $signature === '') {
            throw new InvalidLinkException;
        }

        $invite = GalleryUploadInvite::with(['event', 'rootAlbum'])->find((int) $id);

        if (! $invite || ! $invite->token_nonce) {
            throw new InvalidLinkException;
        }

        // Constant-time. A mismatch means a forged or rotated token.
        if (! hash_equals($this->rawFor($invite), $raw)) {
            throw new InvalidLinkException;
        }

        // Revocation is a status flip — the link dies without touching the
        // photographer's uploads (attribution stays on upload_invite_id).
        if ($invite->status !== GalleryUploadInvite::STATUS_ACTIVE) {
            throw new InvalidLinkException;
        }

        // An event-scoped invite dies with its event; a public-scope invite
        // (null event) has no event switch to check.
        if ($invite->natcon_event_id && ! $invite->event?->is_active) {
            throw new InvalidLinkException;
        }

        if ($invite->token_expires_at && $invite->token_expires_at->isPast()) {
            throw new ExpiredLinkException;
        }

        return $invite;
    }

    /** The URL the admin copies for the photographer. */
    public function buildLink(GalleryUploadInvite $invite, string $rawToken): string
    {
        $base = rtrim(
            (string) config('natcon.gallery.upload_page_url', 'https://filipinohomes.com/natcon/upload'),
            '/',
        );

        return $base.'?'.http_build_query(['t' => $rawToken]);
    }

    /**
     * Default expiry: the event's last day (a wall-clock date in the EVENT's
     * timezone — module rule #4) plus a grace period, so a photographer can
     * finish uploading after the convention without the admin babysitting
     * the link. Null event or no end date = no automatic expiry; revocation
     * remains the lever.
     */
    public function defaultExpiry(?NatconEvent $event): ?Carbon
    {
        if (! $event || ! $event->ends_on) {
            return null;
        }

        return Carbon::parse($event->ends_on->toDateString(), $event->timezone ?: 'Asia/Manila')
            ->endOfDay()
            ->addDays((int) config('natcon.gallery.invite_grace_days', 7))
            ->utc();
    }

    private function rawFor(GalleryUploadInvite $invite): string
    {
        // The 'gallery-invite' domain prefix keeps this token family disjoint
        // from Recipient tokens under the shared secret.
        $material = implode(':', [
            'gallery-invite',
            $invite->id,
            $invite->natcon_event_id ?? 0,
            (string) $invite->token_nonce,
        ]);

        return $invite->id.'.'.hash_hmac('sha256', $material, $this->secret());
    }

    private function hash(string $raw): string
    {
        return hash_hmac('sha256', $raw, $this->secret());
    }

    private function secret(): string
    {
        // config(), never env() — see InviteService::secret() for the outage
        // that rule comes from.
        $secret = (string) config('natcon.link_secret');

        if ($secret === '') {
            throw new \RuntimeException(
                'NATCON_LINK_SECRET is not configured. Generate one with '
                ."php -r 'echo bin2hex(random_bytes(32));' and set it in .env."
            );
        }

        return $secret;
    }
}
