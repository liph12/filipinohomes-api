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
        $conversation = Conversation::with(['chat.user', 'chat.listing'])->find($this->conversationId);
        $message = Message::find($this->messageId);

        if (!$conversation || !$message) {
            return;
        }

        // Only send email when the agent replies for the first time
        // (Admin acceptance already emails the agent via ConversationController::accept)
        if ($this->senderId !== $conversation->agent_user_id) {
            return;
        }

        // Check if this is the agent's first message in the conversation
        $hasEarlierMessages = Message::where('conversation_id', $conversation->id)
            ->where('user_id', $this->senderId)
            ->where('id', '!=', $message->id)
            ->exists();

        if ($hasEarlierMessages) {
            return;
        }

        $sender = User::find($this->senderId);
        $client = $conversation->chat->user;

        if (!$sender || !$client) {
            return;
        }

        // Build slug for the email link
        $listing = $conversation->chat->listing;
        $slug = $listing
            ? Str::slug($listing->name) . '-' . $conversation->chat_id
            : 'chat-' . $conversation->chat_id;

        $roleName = $client->role?->name ?? 'client';

        // Send email notification to the client
        Mail::to($client->email)->send(
            new MessageNotificationMailer($sender, $client, $message->body, $slug, $roleName)
        );
    }
}
