<?php

namespace App\Jobs;

use App\Mail\MessageNotificationMailer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendMessageNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        public int $senderId,
    ) {}

    public function handle(): void
    {
        $conversation = Conversation::with(['chat.user', 'chat.listing', 'users'])->find($this->conversationId);
        $message = Message::find($this->messageId);

        if (!$conversation || !$message) {
            return;
        }

        $sender = User::find($this->senderId);
        if (!$sender) {
            return;
        }

        $chatType = $conversation->chat->type;
        $isListing = $chatType === 'listing';
        $clientUserId = $conversation->chat->user_id;
        $isClientSending = $this->senderId === $clientUserId;

        // Determine the recipient
        if ($isClientSending) {
            // Client is sending → notify the other participant(s)
            if ($isListing) {
                // For listing inquiries, the agent gets notified via ConversationController::accept
                // Only send here if the conversation is already accepted (agent already in conversation)
                if ($conversation->status !== 'accepted' || !$conversation->agent_user_id) {
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

        if (!$recipient) {
            return;
        }

        // Don't email yourself
        if ($recipient->id === $this->senderId) {
            return;
        }

        // Only send on the sender's first message in this conversation
        $hasEarlierMessages = Message::where('conversation_id', $conversation->id)
            ->where('user_id', $this->senderId)
            ->where('id', '!=', $message->id)
            ->exists();

        if ($hasEarlierMessages) {
            return;
        }

        // Build slug for the email link
        $listing = $conversation->chat->listing;
        $slug = $listing
            ? Str::slug($listing->name) . '-' . $conversation->chat_id
            : 'chat-' . $conversation->chat_id;

        $recipientRole = $recipient->role?->name ?? 'client';

        // Send email notification
        Mail::to($recipient->email)->send(
            new MessageNotificationMailer($sender, $recipient, $message->body, $slug, $recipientRole)
        );
    }
}
