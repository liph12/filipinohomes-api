<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatResource;
use App\Models\Chat;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $roleName = $user->role?->name;

        $query = Chat::with(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users', 'activeConversation.agentUser']);

        if ($roleName === 'admin') {
            // admin sees all
        } elseif ($roleName === 'agent') {
            // Agent only sees conversations where they are a participant
            // AND the conversation status is 'accepted' or 'closed' (not 'pending')
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('conversations', function ($sub) use ($user) {
                        $sub->whereHas('users', fn ($u) => $u->where('users.id', $user->id))
                            ->whereIn('status', ['accepted', 'closed']);
                    });
            });
        } else {
            $query->where('user_id', $user->id);
        }

        $chats = $query->latest()->paginate(20);

        // Compute unread counts for each conversation
        foreach ($chats as $chat) {
            if ($chat->relationLoaded('activeConversation') && $chat->activeConversation) {
                $conv = $chat->activeConversation;
                $pivot = $conv->users->firstWhere('id', $user->id)?->pivot;
                $lastReadAt = $pivot?->last_read_at;

                $unreadQuery = $conv->messages()
                    ->where('user_id', '!=', $user->id);

                if ($lastReadAt) {
                    $unreadQuery->where('created_at', '>', $lastReadAt);
                }

                $conv->setAttribute('computed_unread_count', $unreadQuery->count());
            }
        }

        return ChatResource::collection($chats);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Chat::class);

        $user = Auth::user();

        $validated = $request->validate([
            'type' => 'required|in:listing,agent,blog,reel',
            'type_id' => 'required|integer|min:1',
            'target_user_id' => ['required', 'exists:users,id', function ($attribute, $value, $fail) use ($user) {
                if ((int) $value === $user->id) {
                    $fail('You cannot start a chat with yourself.');
                }
            }],
            'message' => 'sometimes|string|max:5000',
        ]);

        $existing = Chat::where('type', $validated['type'])
            ->where('type_id', $validated['type_id'])
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users', 'activeConversation.agentUser']);

            // If the active conversation was rejected, allow re-inquiry by creating a new conversation
            if ($existing->activeConversation && $existing->activeConversation->status === 'rejected') {
                $isListing = $validated['type'] === 'listing';

                DB::transaction(function () use ($existing, $validated, $user, $isListing) {
                    $conversation = Conversation::create([
                        'chat_id' => $existing->id,
                        'status' => $isListing ? 'pending' : 'accepted',
                        'agent_user_id' => $isListing ? $validated['target_user_id'] : null,
                    ]);

                    if ($isListing) {
                        $adminUserIds = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->pluck('id')->toArray();
                        $attachments = [$user->id => ['last_read_at' => now()]];
                        foreach ($adminUserIds as $adminId) {
                            $attachments[$adminId] = ['last_read_at' => null];
                        }
                        $conversation->users()->attach($attachments);
                    } else {
                        $conversation->users()->attach([
                            $user->id => ['last_read_at' => now()],
                            $validated['target_user_id'] => ['last_read_at' => null],
                        ]);
                    }

                    if (!empty($validated['message'])) {
                        Message::create([
                            'conversation_id' => $conversation->id,
                            'user_id' => $user->id,
                            'body' => $validated['message'],
                            'type' => 'text',
                        ]);
                    }
                });

                $existing->touch();
                $existing->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users', 'activeConversation.agentUser']);
            }

            return new ChatResource($existing);
        }

        $chat = DB::transaction(function () use ($validated, $user) {
            $chat = Chat::create([
                'type' => $validated['type'],
                'type_id' => $validated['type_id'],
                'user_id' => $user->id,
            ]);

            $isListing = $validated['type'] === 'listing';

            $conversation = Conversation::create([
                'chat_id' => $chat->id,
                'status' => $isListing ? 'pending' : 'accepted',
                'agent_user_id' => $isListing ? $validated['target_user_id'] : null,
            ]);

            if ($isListing) {
                // Attach client + all admin users (not the agent yet)
                $adminUserIds = User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->pluck('id')->toArray();

                $attachments = [
                    $user->id => ['last_read_at' => now()],
                ];
                foreach ($adminUserIds as $adminId) {
                    $attachments[$adminId] = ['last_read_at' => null];
                }
                $conversation->users()->attach($attachments);
            } else {
                // Non-listing: attach client + target user directly (accepted)
                $conversation->users()->attach([
                    $user->id => ['last_read_at' => now()],
                    $validated['target_user_id'] => ['last_read_at' => null],
                ]);
            }

            if (!empty($validated['message'])) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'body' => $validated['message'],
                    'type' => 'text',
                ]);
            }

            return $chat;
        });

        $chat->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users', 'activeConversation.agentUser']);

        return new ChatResource($chat);
    }

    public function show(Chat $chat)
    {
        $this->authorize('view', $chat);

        $chat->load(['user', 'listing', 'conversations.latestMessage.user', 'conversations.users']);

        return new ChatResource($chat);
    }

    public function destroy(Chat $chat)
    {
        $this->authorize('delete', $chat);

        $chat->delete();

        return response()->json(['message' => 'Chat deleted.']);
    }
}
