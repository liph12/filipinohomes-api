<?php

namespace App\Mail;

use App\Models\User;
use App\Services\TeamLeadershipService;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Sends synchronously. Production has no `php artisan queue:work` worker
// wired up (no supervisor / systemd / cron config in this repo), so when
// this class previously declared `implements ShouldQueue` with the default
// QUEUE_CONNECTION=database driver, every Mail::send(new self(...)) call
// just inserted a row into the `jobs` table and silently sat there — no
// admin / team-leader / agent inquiry email actually went out.
//
// The two endpoints that dispatch this mailable — POST /api/chats
// (submission fan-out to admins + team leader) and POST
// /conversations/{id}/accept (single send to the agent) — are low-frequency
// moderation paths where the ~1–3s SMTP round-trip is acceptable. If a
// queue worker is wired up later, re-add `implements ShouldQueue` plus the
// Queueable trait and no dispatch site needs to change.
class MessageNotificationMailer extends Mailable
{
    use SerializesModels;

    public $receiverEmail;
    public $receiverName;
    public $senderEmail;
    public $senderName;
    public $senderMobile;
    public $senderWhatsapp;
    public $senderAvatar;
    public $message;
    public $slug;
    public $roleName;
    public $listing;
    public $frontendUrl;
    public $greetingName;
    public $agentUserId;
    /**
     * Whether the "Listing Owner" contact block should render in the email.
     * Hidden when the TO recipient IS the listing owner (the agent) — the
     * block would just echo their own contact info. Always shown for the
     * admin/team-leader copy (see dispatchForInquiry below).
     */
    public $showListingOwner;
    /**
     * Per-role view selector. One of: 'admin', 'team_leader', 'agent', or
     * null (legacy / generic message-notification blade used by the
     * reply-path job). Drives view-switching in build().
     */
    public $perspective;
    /**
     * Team name to render in the admin/team-leader callout block. Falls
     * back to a "(unassigned)" / "your team" label in the blade when
     * null (agent has no team).
     */
    public $teamName;
    /**
     * Team id — kept on the mailable for future routing (e.g. team-scoped
     * inbox deep links) even though the current blades don't display it.
     */
    public $teamId;

    public function __construct(
        $sender,
        $receiver,
        $message,
        $slug,
        $roleName = 'agent',
        ?array $listing = null,
        ?int $agentUserId = null,
        bool $showListingOwner = true,
        ?string $perspective = null,
        ?string $teamName = null,
        ?int $teamId = null
    ) {
        $this->receiverEmail = $receiver->email;
        $this->receiverName = $receiver->name;
        $this->senderEmail = $sender->email;
        $this->senderName = $sender->name;
        $this->senderMobile   = $sender->mobile_no ?? null;
        $this->senderWhatsapp = $sender->agent?->whats_app_no ?? null;
        $this->senderAvatar   = $sender->avatar ?? null;
        $this->message = $message;
        $this->slug = $slug;
        $this->roleName = $roleName;
        $this->listing = $listing;
        $this->frontendUrl = rtrim((string) env('FRONTEND_URL', 'https://filipinohomes.com'), '/');
        $this->agentUserId = $agentUserId;
        // Greeting prefers the actual receiver's name so admins receiving
        // a fan-out copy aren't greeted by the listing-owner's name (the
        // legacy behavior — "Hello {owner}" — addressed every recipient
        // as if they were the listing's agent, which read as "I'm sending
        // you an email about yourself" to anyone who wasn't actually the
        // owner). Listing owner name is now only the last-resort fallback
        // when receiverName is empty (e.g. mailable was built with the
        // generic admin persona that has name='Admin' — in that case
        // 'Admin' wins over the listing owner's name).
        $this->greetingName = $this->receiverName ?? ($listing['owner_name'] ?? null);
        $this->showListingOwner = $showListingOwner;
        $this->perspective = $perspective;
        $this->teamName = $teamName;
        $this->teamId = $teamId;
    }

    public function build()
    {
        // Do NOT call $this->to(...) here. The TO address is set by the
        // caller's Mail::to(...) chain (see dispatchForSubmission /
        // dispatchForAcceptance / dispatchForInquiry). If we also call
        // ->to() here, Laravel adds it on top of whatever the caller set
        // — which previously leaked the agent's email into the TO header
        // of the admin fan-out send (commit 6a65b06).
        // MAIL_FROM_ADDRESS is the canonical env name documented in
        // .env.example; env('MAIL_FROM') is undocumented and resolves to
        // null in prod, which made SMTP reject these sends silently once
        // the queue actually started running them. Falls back to the
        // shared inbox so a missing env doesn't break dispatch.
        return $this->from(
            env('MAIL_FROM_ADDRESS', 'info@filipinohomes.com'),
            'FH Support Team',
        )
            ->subject($this->resolveSubject())
            ->markdown($this->resolveView());
    }

    /**
     * Pick the blade view based on the perspective the dispatcher set.
     * Legacy (null) keeps using the existing message-notification blade
     * because the reply-path job at app/Jobs/SendMessageNotification.php
     * still calls dispatchForInquiry() with no perspective.
     */
    private function resolveView(): string
    {
        return match ($this->perspective) {
            'admin'         => 'emails.listing-inquiry-admin',
            'team_leader'   => 'emails.listing-inquiry-team-leader',
            'agent'         => 'emails.listing-inquiry-agent',
            'agent_profile' => 'emails.agent-profile-inquiry',
            default         => 'emails.message-notification',
        };
    }

    /**
     * Subject lines are scannable in a crowded inbox — include the listing
     * name when available so admins triaging multiple pending inquiries
     * can prioritize without opening each one.
     */
    private function resolveSubject(): string
    {
        $listingName = !empty($this->listing['name']) ? (string) $this->listing['name'] : null;
        $suffix = $listingName ? " — {$listingName}" : '';
        $senderName = trim((string) ($this->senderName ?? ''));
        $senderSuffix = $senderName !== '' ? " — {$senderName}" : '';
        return match ($this->perspective) {
            'admin', 'team_leader' => "[Filipino Homes] New inquiry pending review{$suffix}",
            'agent'                => "[Filipino Homes] Inquiry assigned to you{$suffix}",
            'agent_profile'        => "[Filipino Homes] New inquiry from your agent profile{$senderSuffix}",
            default                => 'Filipino Homes - New message received',
        };
    }

    /**
     * Send a follow-up chat-message notification strictly to the
     * conversation's other party. No admin/team-leader fan-out — admins
     * and TLs already saw the moderation moment at submission time (see
     * dispatchForSubmission), and ongoing back-and-forth ("ok thanks",
     * "viewing at 3pm", "is parking included?") doesn't carry moderation
     * value worth flooding their inbox over. Admins retain visibility
     * via /admin/listing-inquiries and /admin/activity-logs without
     * being pushed every chat reply.
     *
     * Earlier this method fanned out a second BCC copy to admins + TLs
     * on every dispatch. That produced noise admins complained about
     * (and, before the sender-exclusion fix, occasionally bounced an
     * admin's own reply back to them). The fan-out is gone.
     */
    public static function dispatchForInquiry(
        $sender,
        $receiver,
        $message,
        $slug,
        string $roleName,
        ?array $listing,
        ?int $agentUserId
    ): void {
        $receiverIsOwner = !empty($listing['owner_email'])
            && strcasecmp((string) $listing['owner_email'], (string) $receiver->email) === 0;

        Mail::to($receiver->email)->send(new self(
            $sender,
            $receiver,
            $message,
            $slug,
            $roleName,
            $listing,
            $agentUserId,
            // Hide the "Listing Owner" card when the receiver IS the
            // owner — they don't need their own info echoed back to
            // them. When receiver is a client, show the card so the
            // client has the agent's contact details handy.
            !$receiverIsOwner,
        ));
    }

    /**
     * Fan-out triggered when a client first submits a listing inquiry. Sends
     * TWO separate emails — one to admins, one to the agent's team leader —
     * each rendered through its own perspective-specific blade so each role
     * sees a template explaining why they're receiving it.
     *
     * Strict BCC pattern (commit 6a65b06 fixed an earlier leak):
     *   - TO header is always 'info@filipinohomes.com' (hardcoded, NOT
     *     env('MAIL_FROM_ADDRESS') — the env var is configured per environment
     *     and locally holds hello@example.com, which would silently break the
     *     shared-inbox illusion).
     *   - BCC carries the actual recipients. Recipient addresses must never
     *     appear in To: or Cc:.
     *   - This applies to the single-recipient team-leader send too: we use
     *     ->bcc([$leader->email]), NOT ->to($leader->email), specifically to
     *     prevent the leader's personal address from leaking on reply-all.
     *
     * The agent is intentionally NOT a recipient at this stage; they only
     * get the inquiry after an admin/team-leader accepts it (see
     * dispatchForAcceptance).
     */
    public static function dispatchForSubmission(
        $sender,
        $message,
        $slug,
        ?array $listing,
        ?int $agentUserId
    ): void {
        $sharedInbox = 'info@filipinohomes.com';
        $senderEmail = (string) ($sender->email ?? '');

        // Resolve the listing-agent's team context once and thread it
        // through both sends so the team_agents pivot isn't hit twice.
        $teamContext = $agentUserId
            ? app(TeamLeadershipService::class)->findTeamInfoForAgent($agentUserId)
            : null;
        $teamId   = $teamContext['team_id']   ?? null;
        $teamName = $teamContext['team_name'] ?? null;

        // --- Email 1: admin BCC fan-out -------------------------------------
        $adminEmails = self::resolveAdminEmails([$senderEmail]);
        if (!empty($adminEmails)) {
            // Receiver is a generic admin persona. The admin blade greets
            // "Hello Admin," and doesn't display the receiver name, so this
            // persona only exists to satisfy the constructor's email/name
            // dereferences.
            $adminPersona = (object) [
                'email' => $sharedInbox,
                'name'  => 'Admin',
            ];
            Mail::to($sharedInbox)->bcc($adminEmails)->send(new self(
                sender:           $sender,
                receiver:         $adminPersona,
                message:          $message,
                slug:             $slug,
                roleName:         'admin',
                listing:          $listing,
                agentUserId:      $agentUserId,
                showListingOwner: true,
                perspective:      'admin',
                teamName:         $teamName,
                teamId:           $teamId,
            ));
        } else {
            Log::warning('dispatchForSubmission: no admin recipients found (role_id=1)');
        }

        // --- Email 2: team-leader BCC (single recipient) --------------------
        $leaderUserId = $teamContext['leader_user_id'] ?? null;
        if ($leaderUserId) {
            $leader = User::find($leaderUserId);
            $leaderEmail = (string) ($leader->email ?? '');
            $isSender    = $leaderEmail !== '' && strcasecmp($leaderEmail, $senderEmail) === 0;
            // Dedup against the admin list: a user with role_id=1 who is
            // also flagged as team leader already received the admin email.
            $adminEmailsLower = array_map('strtolower', $adminEmails);
            $alreadyEmailed   = in_array(strtolower($leaderEmail), $adminEmailsLower, true);

            if ($leader && $leaderEmail !== '' && !$isSender && !$alreadyEmailed) {
                Mail::to($sharedInbox)->bcc([$leaderEmail])->send(new self(
                    sender:           $sender,
                    receiver:         $leader,
                    message:          $message,
                    slug:             $slug,
                    roleName:         'team_leader',
                    listing:          $listing,
                    agentUserId:      $agentUserId,
                    showListingOwner: true,
                    perspective:      'team_leader',
                    teamName:         $teamName,
                    teamId:           $teamId,
                ));
            }
        }
    }

    /**
     * Sent when an admin or team-leader accepts a pending listing inquiry.
     * Goes strictly to the listing-agent — admins/team-leader already saw
     * the submission email, so a second copy here would just be noise.
     *
     * BCC pattern preserved even for this single recipient: TO is the
     * shared inbox and the agent's address lives in BCC only.
     */
    public static function dispatchForAcceptance(
        $sender,
        $agent,
        $message,
        $slug,
        ?array $listing,
        ?int $agentUserId
    ): void {
        $sharedInbox = 'info@filipinohomes.com';
        $agentEmail  = (string) ($agent->email ?? '');
        if ($agentEmail === '') {
            Log::warning('dispatchForAcceptance: agent has no email — skipping send', [
                'agent_user_id' => $agentUserId,
            ]);
            return;
        }

        Mail::to($sharedInbox)->bcc([$agentEmail])->send(new self(
            sender:           $sender,
            receiver:         $agent,
            message:          $message,
            slug:             $slug,
            roleName:         'agent',
            listing:          $listing,
            agentUserId:      $agentUserId,
            showListingOwner: false,
            perspective:      'agent',
        ));
    }

    /**
     * Sent when a visitor submits the "Message Me" form on a public agent
     * profile (POST /chats with type=agent). Unlike listing inquiries this
     * skips moderation entirely — the conversation is auto-accepted on
     * create — so the agent is the only recipient. No admin/team-leader
     * fan-out: profile inquiries are a direct visitor-to-agent channel and
     * the admins don't moderate them, so a BCC copy would just be noise.
     *
     * Same BCC pattern as the other dispatchers: TO is the shared inbox,
     * the agent's address lives in BCC only so reply-all doesn't leak it.
     * No listing context — agent-profile inquiries aren't tied to a unit.
     */
    public static function dispatchForAgentProfile(
        $sender,
        $agent,
        $message,
        $slug,
        ?int $agentUserId
    ): void {
        $sharedInbox = 'info@filipinohomes.com';
        $agentEmail  = (string) ($agent->email ?? '');
        if ($agentEmail === '') {
            Log::warning('dispatchForAgentProfile: agent has no email — skipping send', [
                'agent_user_id' => $agentUserId,
            ]);
            return;
        }

        // Don't email when the sender IS the agent (self-conversation isn't
        // valid through the form, but the validation lives elsewhere — be
        // defensive here so a missed validation can't produce a noise email).
        $senderEmail = (string) ($sender->email ?? '');
        if ($senderEmail !== '' && strcasecmp($senderEmail, $agentEmail) === 0) {
            return;
        }

        Mail::to($sharedInbox)->bcc([$agentEmail])->send(new self(
            sender:           $sender,
            receiver:         $agent,
            message:          $message,
            slug:             $slug,
            roleName:         'agent',
            listing:          null,
            agentUserId:      $agentUserId,
            showListingOwner: false,
            perspective:      'agent_profile',
        ));
    }

    /**
     * Just the admin (role_id=1) audience, deduplicated, with every email
     * in $excludeEmails stripped out. Used by dispatchForSubmission to
     * exclude the sender (an admin testing the form should not BCC
     * themselves).
     *
     * @param array<int, string> $excludeEmails
     * @return array<int, string>
     */
    private static function resolveAdminEmails(array $excludeEmails): array
    {
        return array_values(array_unique(array_filter(
            User::where('role_id', 1)->pluck('email')->all(),
            fn ($email) => $email && !self::emailMatchesAny((string) $email, $excludeEmails),
        )));
    }

    /**
     * Case-insensitive "is this email in the exclusion list" check.
     * Empty strings in $excludeEmails are ignored (a caller passing
     * a missing sender->email shouldn't accidentally strip every
     * empty-string email from the admin list — there are none, but
     * being defensive keeps the helper safe to call from any caller).
     */
    private static function emailMatchesAny(string $email, array $excludeEmails): bool
    {
        $emailLower = strtolower(trim($email));
        if ($emailLower === '') {
            return false;
        }
        foreach ($excludeEmails as $exclude) {
            $excludeLower = strtolower(trim((string) $exclude));
            if ($excludeLower !== '' && $excludeLower === $emailLower) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the property-card payload for the email template from a Listing
     * Eloquent model. Pulls just the strings/URLs the blade renders so the
     * queued job doesn't have to serialize the whole model graph.
     *
     * @param  mixed $listing  Listing model (loaded with property + category)
     * @return array<string, mixed>|null
     */
    public static function buildListingPayload($listing): ?array
    {
        if (!$listing) {
            return null;
        }

        $photo = null;
        $raw = $listing->featured_photo ?? null;
        if (is_array($raw) && !empty($raw[0])) {
            $photo = (string) $raw[0];
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $photo = is_array($decoded) && !empty($decoded[0]) ? (string) $decoded[0] : $raw;
        }
        if (!$photo) {
            $propertyPhotos = $listing->property?->photos;
            if (is_array($propertyPhotos) && !empty($propertyPhotos[0])) {
                $photo = (string) $propertyPhotos[0];
            }
        }

        $barangay = $listing->property?->barangay;
        $city     = $barangay?->city;
        $province = $city?->province;
        $locParts = array_filter([
            $barangay?->name,
            $city?->name,
            $province?->name,
        ]);
        $location = !empty($locParts)
            ? implode(', ', $locParts)
            : ($listing->property?->address ?? null);

        $price = $listing->price ?? null;
        if ($price !== null && is_numeric($price)) {
            $price = 'PHP ' . number_format((float) $price);
        }

        $subtype = $listing->property?->propertyAttribute?->subtype;
        $typeName = $subtype?->type?->name;
        $subtypeName = $subtype?->name;

        $agent = $listing->agent ?? null;
        $ownerName = null;
        $ownerMobile = null;
        $ownerWhatsapp = null;
        $ownerEmail = null;
        $ownerAvatar = null;
        if ($agent) {
            $ownerName     = trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')) ?: null;
            $ownerMobile   = $agent->mobile_no    ?? null;
            $ownerWhatsapp = $agent->whats_app_no ?? null;
            $ownerEmail    = $agent->user?->email ?? null;
            // Use the related User's avatar (a plain URL string). Agent's
            // own `avatar` column is JSON-cast and its structure isn't
            // guaranteed to be a single URL, so we skip it here.
            $ownerAvatar   = $agent->user?->avatar ?? null;
        }

        $frontend = rtrim((string) env('FRONTEND_URL', 'https://filipinohomes.com'), '/');
        $publicUrl = !empty($listing->slug) ? "{$frontend}/listings/{$listing->slug}" : null;

        return [
            'name'           => $listing->name ?? null,
            'price'          => $price,
            'image'          => $photo,
            'location'       => $location,
            'category'       => $listing->category?->name ?? null,
            'subtype'        => $subtypeName ? trim(($typeName ? "{$typeName} · " : '') . $subtypeName) : $typeName,
            'public_url'     => $publicUrl,
            'owner_name'     => $ownerName,
            'owner_mobile'   => $ownerMobile,
            'owner_whatsapp' => $ownerWhatsapp,
            'owner_email'    => $ownerEmail,
            'owner_avatar'   => $ownerAvatar,
        ];
    }
}
