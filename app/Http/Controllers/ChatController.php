<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChatResource;
use App\Models\Chat;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $roleName = $user->role?->name;

        $query = Chat::with(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users']);

        if ($roleName === 'admin') {
            // admin sees all
        } elseif ($roleName === 'agent') {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('conversations.users', fn ($sub) => $sub->where('users.id', $user->id));
            });
        } else {
            $query->where('user_id', $user->id);
        }

        $chats = $query->latest()->paginate(20);

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
            $existing->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users']);
            return new ChatResource($existing);
        }

        $chat = DB::transaction(function () use ($validated, $user) {
            $chat = Chat::create([
                'type' => $validated['type'],
                'type_id' => $validated['type_id'],
                'user_id' => $user->id,
            ]);

            $conversation = Conversation::create([
                'chat_id' => $chat->id,
                'status' => 'active',
            ]);

            $conversation->users()->attach([
                $user->id => ['last_read_at' => now()],
                $validated['target_user_id'] => ['last_read_at' => null],
            ]);

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

        $chat->load(['user', 'listing', 'activeConversation.latestMessage.user', 'activeConversation.users']);

        return new ChatResource($chat);
    }

    public function show(Chat $chat)
    {
        $this->authorize('view', $chat);

        $chat->load(['user', 'listing', 'conversations.latestMessage.user', 'conversations.users']);

        return new ChatResource($chat);
    }
}
