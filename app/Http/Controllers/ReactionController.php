<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', Auth::id())
            ->where('emoji', $validated['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => Auth::id(),
                'emoji' => $validated['emoji'],
            ]);
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
}
