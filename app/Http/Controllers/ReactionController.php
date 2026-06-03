<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Services\ExpoPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReactionController extends Controller
{
    public function toggle(Request $request, Message $message)
    {
        $validated = $request->validate([
            'emoji' => 'required|string|max:32',
        ]);

        $conversation = $message->conversation;

        if (!$conversation->users()->where('users.id', Auth::id())->exists()
            && Auth::user()->role?->name !== 'admin') {
            abort(403, 'You are not a participant in this conversation.');
        }

        // One reaction per user per message (Messenger-style): the same
        // emoji toggles off, a different emoji replaces the previous one.
        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', Auth::id())
            ->first();

        $isNewReaction = false;
        if ($existing) {
            if ($existing->emoji === $validated['emoji']) {
                $existing->delete();
            } else {
                $existing->update(['emoji' => $validated['emoji']]);
                $isNewReaction = true;
            }
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => Auth::id(),
                'emoji' => $validated['emoji'],
            ]);
            $isNewReaction = true;
        }

        // Notify the message author when someone else adds/changes a
        // reaction (never on self-reaction or removal).
        if ($isNewReaction) {
            $this->notifyAuthor($message, $validated['emoji']);
        }

        $reactions = $message->reactions()->with('user')->get();

        $grouped = $reactions->groupBy('emoji')->map(function ($group, $emoji) {
            return [
                'emoji' => $emoji,
                'count' => $group->count(),
                'users' => $group->map(fn ($r) => [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'message_id' => $message->id,
                'reactions' => $grouped,
            ],
        ]);
    }

    /**
     * Push "Reacted {emoji} to your message: {preview}" to the author of the
     * reacted message, mirroring the new-message notification. Only app users
     * (agents/admins) have device tokens + an in-app feed, and we never notify
     * someone for reacting to their own message. Non-fatal on failure.
     */
    private function notifyAuthor(Message $message, string $emoji): void
    {
        $author = $message->user;
        if (!$author || $author->id === Auth::id()) {
            return;
        }

        if (!in_array($author->role?->name ?? 'client', ['agent', 'admin'], true)) {
            return;
        }

        $preview = $message->body ? Str::limit($message->body, 60) : null;
        $body = $preview
            ? "Reacted {$emoji} to your message: {$preview}"
            : "Reacted {$emoji} to your message";

        try {
            app(ExpoPushService::class)->notify(
                $author,
                'inquiry',
                Auth::user()->name ?? 'Someone',
                $body,
                ['type' => 'inquiry', 'id' => $message->conversation->chat_id],
            );
        } catch (\Throwable $e) {
            Log::warning('Push notify (reaction) failed', [
                'message_id' => $message->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
