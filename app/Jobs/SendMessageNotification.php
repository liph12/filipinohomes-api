<?php

namespace App\Jobs;

use App\Mail\MessageNotificationMailer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
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
        $conversation = Conversation::with(['users', 'chat.user', 'chat.listing'])->find($this->conversationId);
        $message = Message::find($this->messageId);

        if (!$conversation || !$message) {
            return;
        }

        $sender = User::find($this->senderId);

        if (!$sender) {
            return;
        }

        $cooldown = now()->subMinutes(30);

        foreach ($conversation->users as $user) {
            // Don't notify the sender
            if ($user->id === $this->senderId) {
                continue;
            }

            $pivot = $user->pivot;

            // Skip if user has read recently (they're active on the page)
            if ($pivot->last_read_at && Carbon::parse($pivot->last_read_at)->isAfter($message->created_at)) {
                continue;
            }

            // Skip if notified recently (30-minute cooldown)
            if ($pivot->last_notified_at && Carbon::parse($pivot->last_notified_at)->isAfter($cooldown)) {
                continue;
            }

            // Build slug for the email link
            $listing = $conversation->chat->listing;
            $slug = $listing
                ? Str::slug($listing->name) . '-' . $conversation->chat_id
                : 'chat-' . $conversation->chat_id;

            // Determine the recipient's dashboard path based on role
            $roleName = $user->role?->name ?? 'client';

            // Send email
            Mail::to($user->email)->queue(
                new MessageNotificationMailer($sender, $user, $message->body, $slug, $roleName)
            );

            // Update last_notified_at to prevent rapid-fire emails
            $conversation->users()->updateExistingPivot($user->id, [
                'last_notified_at' => now(),
            ]);
        }
    }
}
