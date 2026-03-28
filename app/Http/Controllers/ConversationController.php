<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Chat;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'chat_id' => 'required|exists:chats,id',
        ]);

        $chat = Chat::findOrFail($validated['chat_id']);
        $this->authorize('view', $chat);

        $user = Auth::user();
        $query = Conversation::where('chat_id', $validated['chat_id']);

        // Agents should only see accepted/closed conversations, not pending ones
        if ($user->role?->name === 'agent') {
            $query->whereIn('status', ['accepted', 'closed']);
        }

        $conversations = $query->with(['latestMessage.user', 'users'])
            ->latest()
            ->paginate(20);

        return ConversationResource::collection($conversations);
    }

    public function show(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $conversation->load(['messages.user', 'users']);

        $user = Auth::user();
        $conversation->users()->updateExistingPivot($user->id, [
            'last_read_at' => now(),
        ]);

        return new ConversationResource($conversation);
    }

    public function accept(Conversation $conversation)
    {
        $this->authorize('moderate', $conversation);

        if ($conversation->status !== 'pending') {
            return response()->json(['message' => 'Only pending conversations can be accepted.'], 422);
        }

        $user = Auth::user();

        $conversation->update([
            'status' => 'accepted',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        // Add the agent to conversation_users so they can see the full history
        if ($conversation->agent_user_id) {
            $conversation->users()->syncWithoutDetaching([
                $conversation->agent_user_id => ['last_read_at' => null],
            ]);
        }

        $conversation->load(['latestMessage.user', 'users', 'reviewedBy']);

        return new ConversationResource($conversation);
    }

    public function reject(Conversation $conversation)
    {
        $this->authorize('moderate', $conversation);

        if ($conversation->status !== 'pending') {
            return response()->json(['message' => 'Only pending conversations can be rejected.'], 422);
        }

        $user = Auth::user();

        $conversation->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $conversation->load(['latestMessage.user', 'users', 'reviewedBy']);

        return new ConversationResource($conversation);
    }

    public function markRead(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $user = Auth::user();
        $now = now();

        $conversation->users()->updateExistingPivot($user->id, [
            'last_read_at' => $now,
        ]);

        // Mark individual messages as read
        $conversation->messages()
            ->where('user_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        return response()->json([
            'message' => 'Marked as read.',
            'read_at' => $now->toISOString(),
        ]);
    }
}
