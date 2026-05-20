<?php

namespace App\Mail;

use App\Models\User;
use App\Services\TeamLeadershipService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class MessageNotificationMailer extends Mailable
{
    use Queueable, SerializesModels;

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

    public function __construct(
        $sender,
        $receiver,
        $message,
        $slug,
        $roleName = 'agent',
        ?array $listing = null,
        ?int $agentUserId = null,
        bool $showListingOwner = true
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
        $this->greetingName = $listing['owner_name'] ?? $this->receiverName;
        $this->showListingOwner = $showListingOwner;
    }

    public function build()
    {
        return $this->to($this->receiverEmail)
            ->from(env('MAIL_FROM'), 'FH Support Team')
            ->subject('Filipino Homes - New message received')
            ->markdown('emails.message-notification');
    }

    /**
     * Send the inquiry notification to the right audience(s). When the TO
     * recipient is the listing owner themselves, their copy hides the
     * "Listing Owner" block (redundant — it'd echo their own info), and a
     * separate copy goes to admins + team leader WITH the block so they can
     * see who's responsible.
     *
     * Replaces the previous Mail::to(...)->send(new self(...)) pattern at the
     * callsites — they only need to know about this method now.
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

        // Email 1: to the primary recipient (agent or client).
        Mail::to($receiver->email)->send(new self(
            $sender,
            $receiver,
            $message,
            $slug,
            $roleName,
            $listing,
            $agentUserId,
            !$receiverIsOwner,
        ));

        // Email 2: to admins + team leader, with the listing-owner card
        // visible so they always know who's responsible. Skipped when the
        // audience is empty (no admins configured).
        $audience = self::resolveAdminAudience($agentUserId, $receiver->email);
        if (!empty($audience)) {
            Mail::to($audience)->send(new self(
                $sender,
                $receiver,
                $message,
                $slug,
                $roleName,
                $listing,
                $agentUserId,
                true,
            ));
        }
    }

    /**
     * Resolve the admin audience for an inquiry: all role_id=1 users plus
     * the team leader of the listing's owning agent (if known). Excludes
     * the TO recipient so they don't get a second duplicate copy.
     *
     * @return array<int, string>
     */
    private static function resolveAdminAudience(?int $agentUserId, string $excludeEmail): array
    {
        $emails = User::where('role_id', 1)->pluck('email')->all();

        if ($agentUserId) {
            $leaderUserId = app(TeamLeadershipService::class)->findTeamLeaderUserIdFor($agentUserId);
            if ($leaderUserId) {
                $leaderEmail = User::where('id', $leaderUserId)->value('email');
                if ($leaderEmail) {
                    $emails[] = $leaderEmail;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $emails,
            fn ($email) => $email && strcasecmp($email, $excludeEmail) !== 0,
        )));
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
