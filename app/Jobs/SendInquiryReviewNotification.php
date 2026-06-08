<?php

namespace App\Jobs;

use App\Mail\MessageNotificationMailer;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use App\Services\ExpoPushService;
use App\Services\TeamLeadershipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fires when a client submits a listing inquiry. Notifies the moderators (the
 * admins + the agent's team leader, and the agent once the inquiry is accepted)
 * with a rich "listing_inquiry" notification that names the property and deep-
 * links to the in-app review detail screen — distinct from the chat-message
 * digest that subsequent messages produce.
 *
 * Only recipients who prefer push (and are signed in on a device) get the push
 * here; everyone else receives the submission/acceptance email already sent by
 * ChatController::store (see MessageNotificationMailer channel gating).
 */
class SendInquiryReviewNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        public int $senderId,
    ) {}

    public function handle(): void
    {
        $conversation = Conversation::with(['chat.user', 'chat.listing', 'users.role'])
            ->find($this->conversationId);
        $message = Message::find($this->messageId);

        if (! $conversation || ! $message) {
            return;
        }

        $chat = $conversation->chat;
        if (! $chat || $chat->type !== 'listing') {
            return;
        }

        $client = $chat->user; // the inquirer
        $clientUserId = $chat->user_id;

        // Recipients: every app-user participant except the sender and the
        // client, restricted to agent/admin roles. On a pending lead this is
        // admins + the team leader; once accepted the assigned agent is a
        // participant too — exactly the confirmed audience, no extra branching.
        $recipients = $conversation->users
            ->filter(function ($u) use ($clientUserId) {
                if (! $u || $u->id === $this->senderId || $u->id === $clientUserId) {
                    return false;
                }
                $role = $u->role?->name ?? 'client';

                return in_array($role, ['agent', 'admin'], true);
            })
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        // Load the listing with the relation graph buildListingPayload reads
        // (same shape ChatController uses for the email card). The listing FK
        // on `chats` is the polymorphic `type_id` column, not `listing_id`.
        $listingId = $chat->type_id;
        $listing = $listingId
            ? Listing::with([
                'agent',
                'category',
                'property.barangay.city.province',
                'property.propertyAttribute.subtype.type',
            ])->find($listingId)
            : null;

        $listingCard = MessageNotificationMailer::buildListingPayload($listing) ?? [];
        $listingName = $listingCard['name'] ?? 'a listing';
        $clientName = $client->name ?? 'A client';

        // Agent team context — drives the in-app "Agent Team" / "Action Needed
        // — Agent Not On A Team" box and the team-leader footer, mirroring the
        // email blades. Resolved once for all recipients.
        $agentUserId = $listing?->agent?->user_id;
        $teamContext = $agentUserId
            ? app(TeamLeadershipService::class)->findTeamInfoForAgent($agentUserId)
            : null;
        $teamName = $teamContext['team_name'] ?? null;
        $leaderUserId = $teamContext['leader_user_id'] ?? null;

        $payload = array_merge($listingCard, [
            'type' => 'listing_inquiry',
            'chat_id' => $chat->id,
            'listing_id' => $listingId,
            'conversation_id' => $conversation->id,
            'client_name' => $clientName,
            'client_avatar' => $client->avatar ?? null,
            'client_email' => $client->email ?? null,
            'client_phone' => $client->mobile_no ?? null,
            'message' => $message->body,
            'sent_at' => optional($message->created_at)->toIso8601String(),
            'team_name' => $teamName,
            'has_team' => (bool) $teamName,
        ]);

        $title = 'New listing inquiry to review';
        $body = $clientName.' inquired on '.$listingName;
        if ($message->body) {
            $body .= ' — '.Str::limit($message->body, 80);
        }

        foreach ($recipients as $recipient) {
            // Only push-preferring app users get the push + feed row here; the
            // rest are covered by the inquiry email.
            if (! $recipient->prefersInquiryPush()) {
                continue;
            }

            // Per-recipient perspective so the detail screen reads like the
            // email that recipient would have received (admin / team-leader /
            // agent variants of greeting, callout and footer).
            if ($leaderUserId && $recipient->id === $leaderUserId) {
                $recipientRole = 'team_leader';
            } elseif ($agentUserId && $recipient->id === $agentUserId) {
                $recipientRole = 'agent';
            } else {
                $recipientRole = 'admin';
            }
            $recipientPayload = array_merge($payload, [
                'recipient_role' => $recipientRole,
                'recipient_name' => $recipient->name,
            ]);

            try {
                app(ExpoPushService::class)->notify(
                    $recipient,
                    'listing_inquiry',
                    $title,
                    $body,
                    $recipientPayload,
                );
            } catch (\Throwable $e) {
                Log::warning('Push notify (listing inquiry) failed', [
                    'conversation_id' => $conversation->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
