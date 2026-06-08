<?php

namespace App\Jobs;

use App\Mail\MessageNotificationMailer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SendMessageNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        public int $senderId,
    ) {}

    /**
     * First usable image URL for a listing — its featured photo, falling back
     * to the related property's photos. Tolerates both array-cast and raw JSON
     * string storage. Used as the notification avatar so inquiry rows show the
     * property instead of a generic icon.
     */
    private function listingPhoto($listing): ?string
    {
        if (! $listing) {
            return null;
        }

        $raw = $listing->featured_photo ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [$raw];
        }
        if (is_array($raw) && ! empty($raw[0]) && is_string($raw[0])) {
            return $raw[0];
        }

        $propertyPhotos = $listing->property?->photos;
        if (is_array($propertyPhotos) && ! empty($propertyPhotos[0]) && is_string($propertyPhotos[0])) {
            return $propertyPhotos[0];
        }

        return null;
    }

    public function handle(): void
    {
        $conversation = Conversation::with(['chat.user', 'chat.listing', 'users.role'])->find($this->conversationId);
        $message = Message::find($this->messageId);

        if (! $conversation || ! $message) {
            return;
        }

        // Eager-load the agent profile so the mailer can read
        // $sender->agent?->whats_app_no when the sender is an agent.
        $sender = User::with('agent')->find($this->senderId);
        if (! $sender) {
            return;
        }

        $chatType = $conversation->chat->type;
        $isListing = $chatType === 'listing';
        $clientUserId = $conversation->chat->user_id;
        $isClientSending = $this->senderId === $clientUserId;

        // ── PUSH recipients ──────────────────────────────────────────
        // Push fires in real time on every message. We notify every app
        // user (agent/admin) who is part of the thread except the sender,
        // NOT just the listing's assigned agent. This is what lets an
        // admin / team leader moderating a conversation receive a push when
        // the client replies — they're attached as a participant the moment
        // they send their first message (see MessageController::store), so
        // they show up here on every subsequent client message.
        $pushRecipients = collect();
        if ($isClientSending) {
            foreach ($conversation->users as $participant) {
                if ($participant->id === $this->senderId || $participant->id === $clientUserId) {
                    continue;
                }
                $pushRecipients->push($participant);
            }
            // The assigned agent is attached to the thread on accept, but
            // include them defensively (accepted threads only — pending
            // listing leads are still handled by the submission/accept
            // emails, not a per-message push to an agent who hasn't joined).
            if ($isListing
                && $conversation->status === 'accepted'
                && $conversation->agent_user_id
                && ! $pushRecipients->contains('id', $conversation->agent_user_id)) {
                if ($agentUser = User::with('role')->find($conversation->agent_user_id)) {
                    $pushRecipients->push($agentUser);
                }
            }
        } elseif ($conversation->chat->user) {
            // Agent/admin replying → notify the client.
            $pushRecipients->push($conversation->chat->user);
        }

        $pushRecipients = $pushRecipients
            ->filter(fn ($u) => $u && $u->id !== $this->senderId)
            ->unique('id')
            ->values();

        if ($pushRecipients->isNotEmpty()) {
            $chat = $conversation->chat;
            $isDirect = $chat->type !== 'listing';
            $senderName = $sender->name ?? 'New message';
            $preview = $message->body ? Str::limit($message->body, 120) : 'Sent an attachment';

            // Rich, data-only payload the mobile app renders with Notifee
            // (Messenger-style). 'type'/'id' stay for deep-link routing.
            // Recipient-independent, so build once and reuse per device.
            $payload = [
                'type' => 'inquiry',
                'id' => $conversation->chat_id,
                'conversation_id' => $conversation->id,
                'thread_key' => 'conv-'.$conversation->id,
                'sender_name' => $senderName,
                'sender_avatar' => $sender->avatar,
                're_label' => $isDirect ? null : ($chat->listing->name ?? null),
                'listing_id' => $isDirect ? null : ($chat->listing->id ?? null),
                'listing_photo' => $isDirect ? null : $this->listingPhoto($chat->listing ?? null),
                'is_direct' => $isDirect,
                'message_id' => $message->id,
                'body' => $message->body,
                'message_type' => $message->type,
                'has_attachment' => ! empty($message->attachments),
                'sent_at' => optional($message->created_at)->toIso8601String(),
            ];

            foreach ($pushRecipients as $pushRecipient) {
                // Only app users (agents/admins) have device tokens + an
                // in-app feed; the mobile inquiry route is keyed on chat_id.
                $roleName = $pushRecipient->role?->name ?? 'client';
                if (! in_array($roleName, ['agent', 'admin'], true)) {
                    continue;
                }
                try {
                    app(ExpoPushService::class)->notifyMessage(
                        $pushRecipient,
                        $senderName,
                        $preview,
                        $payload,
                    );
                } catch (\Throwable $e) {
                    Log::warning('Push notify (message) failed', [
                        'conversation_id' => $conversation->id,
                        'recipient_id' => $pushRecipient->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── EMAIL recipient (throttled channel — unchanged targeting) ──
        // Determine the single primary recipient for the email path.
        if ($isClientSending) {
            // Client is sending → notify the other participant(s)
            if ($isListing) {
                // For listing inquiries, the agent gets notified via ConversationController::accept
                // Only send here if the conversation is already accepted (agent already in conversation)
                if ($conversation->status !== 'accepted' || ! $conversation->agent_user_id) {
                    return;
                }
                $recipient = User::find($conversation->agent_user_id);
            } else {
                // For direct messages (agent, blog, reel): find the other participant
                $recipient = $conversation->users->first(fn ($u) => $u->id !== $this->senderId);
            }
        } else {
            // Agent/other user is replying → notify the client
            $recipient = $conversation->chat->user;
        }

        if (! $recipient) {
            return;
        }

        // Don't email yourself
        if ($recipient->id === $this->senderId) {
            return;
        }

        // Send email if:
        // 1. Sender's first message in this conversation, OR
        // 2. Last message in conversation was sent 24+ hours ago (re-notification)
        $hasEarlierMessages = Message::where('conversation_id', $conversation->id)
            ->where('user_id', $this->senderId)
            ->where('id', '!=', $message->id)
            ->exists();

        if ($hasEarlierMessages) {
            $lastMessage = Message::where('conversation_id', $conversation->id)
                ->where('id', '!=', $message->id)
                ->latest('created_at')
                ->first();

            if (! $lastMessage || $lastMessage->created_at->gt(now()->subHours(24))) {
                return; // Last message within 24h — skip notification
            }
        }

        // Build slug for the email link
        $listing = $conversation->chat->listing;
        $slug = $listing
            ? Str::slug($listing->name).'-'.$conversation->chat_id
            : 'chat-'.$conversation->chat_id;

        // Load the relations the email's property card needs (category,
        // location chain, property type/subtype). Direct messages without a
        // listing skip this entirely.
        if ($listing) {
            $listing->load([
                'agent',
                'category',
                'property.barangay.city.province',
                'property.propertyAttribute.subtype.type',
            ]);
        }

        $recipientRole = $recipient->role?->name ?? 'client';

        // Send email notification — dispatchForInquiry handles the
        // TO-vs-admin-BCC split so the listing owner doesn't see a redundant
        // copy of their own contact info.
        MessageNotificationMailer::dispatchForInquiry(
            $sender,
            $recipient,
            $message->body,
            $slug,
            $recipientRole,
            MessageNotificationMailer::buildListingPayload($listing),
            $conversation->agent_user_id,
        );

        // Update last_notified_at for the recipient
        $conversation->users()->updateExistingPivot($recipient->id, [
            'last_notified_at' => now(),
        ]);
    }
}
