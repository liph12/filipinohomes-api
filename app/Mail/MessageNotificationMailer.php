<?php

namespace App\Mail;

use App\Models\User;
use App\Services\TeamLeadershipService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MessageNotificationMailer extends Mailable
{
    use Queueable, SerializesModels;

    public $receiverEmail;
    public $receiverName;
    public $senderEmail;
    public $senderName;
    public $senderMobile;
    public $senderWhatsapp;
    public $message;
    public $slug;
    public $roleName;
    /**
     * Optional listing payload shown as a property card in the email so the
     * recipient knows which listing the inquiry/message refers to.
     * Shape: ['name', 'price', 'image', 'location', 'category', 'subtype',
     * 'public_url'] — all strings, all optional.
     */
    public $listing;
    /**
     * Frontend base URL (e.g. https://filipinohomes.com or a staging host).
     * Driven by FRONTEND_URL env so emails don't hardcode prod — same value
     * is used to compose role-aware deep links in the blade.
     */
    public $frontendUrl;
    public $agentUserId;

    public function __construct(
        $sender,
        $receiver,
        $message,
        $slug,
        $roleName = 'agent',
        ?array $listing = null,
        ?int $agentUserId = null
    ) {
        $this->receiverEmail = $receiver->email;
        $this->receiverName = $receiver->name;
        $this->senderEmail = $sender->email;
        $this->senderName = $sender->name;
        // Mobile sits on the User row; WhatsApp lives on the related Agent
        // profile (only present for users with role=agent). Both optional so
        // direct-client senders just show what they have.
        $this->senderMobile   = $sender->mobile_no ?? null;
        $this->senderWhatsapp = $sender->agent?->whats_app_no ?? null;
        $this->message = $message;
        $this->slug = $slug;
        $this->roleName = $roleName;
        $this->listing = $listing;
        // Resolve once so the blade can compose deep links for any role:
        //   {$frontendUrl}/{role}/listing-inquiries/{$slug}
        // Falls back to prod when FRONTEND_URL isn't set in .env.
        $this->frontendUrl = rtrim((string) env('FRONTEND_URL', 'https://filipinohomes.com'), '/');
        $this->agentUserId = $agentUserId;
    }

    public function build()
    {
        $bccEmails = User::where('role_id', 1)->pluck('email')->all();

        if ($this->agentUserId) {
            $leaderUserId = app(TeamLeadershipService::class)->findTeamLeaderUserIdFor($this->agentUserId);
            if ($leaderUserId) {
                $leaderEmail = User::where('id', $leaderUserId)->value('email');
                if ($leaderEmail) {
                    $bccEmails[] = $leaderEmail;
                }
            }
        }

        $bccEmails = array_values(array_unique(array_filter(
            $bccEmails,
            fn ($email) => $email && strcasecmp($email, $this->receiverEmail) !== 0
        )));

        return $this->to($this->receiverEmail)
            ->from(env('MAIL_FROM'), 'FH Support Team')
            ->subject('Filipino Homes - New message received')
            ->bcc($bccEmails)
            ->markdown('emails.message-notification');
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

        // featured_photo is JSON-cast to array on the Listing model; take the
        // first URL. Falls back to first property photo when missing.
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

        // Compose location from the deepest available level — barangay > city
        // > province > raw address.
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

        // Format price as "PHP 12,345,678" when numeric, otherwise pass through.
        $price = $listing->price ?? null;
        if ($price !== null && is_numeric($price)) {
            $price = 'PHP ' . number_format((float) $price);
        }

        $subtype = $listing->property?->propertyAttribute?->subtype;
        $typeName = $subtype?->type?->name;
        $subtypeName = $subtype?->name;

        $frontend = rtrim((string) env('FRONTEND_URL', 'https://filipinohomes.com'), '/');
        $publicUrl = !empty($listing->slug) ? "{$frontend}/listings/{$listing->slug}" : null;

        return [
            'name'       => $listing->name ?? null,
            'price'      => $price,
            'image'      => $photo,
            'location'   => $location,
            'category'   => $listing->category?->name ?? null,
            'subtype'    => $subtypeName ? trim(($typeName ? "{$typeName} · " : '') . $subtypeName) : $typeName,
            'public_url' => $publicUrl,
        ];
    }
}
