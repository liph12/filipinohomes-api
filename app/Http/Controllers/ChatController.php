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
    public function index(Request $request)
    {
        $user = Auth::user();
        $roleName = $user->role?->name;

        // Compact eager load — no users[] / agent_user / property eager join.
        // Show endpoint hydrates the rest on demand.
        $query = Chat::with([
            'user.role',
            'user.agent:id,user_id,mobile_no',
            'listing:id,name,slug,price,featured_photo,property_id',
            'listing.property:id,status',
            'activeConversation',
            'activeConversation.users.role',
            'activeConversation.users.agent:id,user_id,mobile_no',
            'activeConversation.agentUser.role',
            'activeConversation.agentUser.agent:id,user_id,mobile_no',
            'activeConversation.latestMessage.user.role',
            'activeConversation.latestMessage.user.agent:id,user_id,mobile_no',
        ]);

        if ($roleName === 'admin') {
            // admin sees all
        } elseif ($roleName === 'agent') {
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

        // Optional filters used by both the inquiries-page chips and the
        // single-row lookup callers (ContactForm / AgentDetailPage / useInquiry).
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($typeId = $request->query('type_id')) {
            $query->where('type_id', (int) $typeId);
        }
        if ($status = $request->query('status')) {
            if ($status !== 'all') {
                $query->whereHas('activeConversation', fn ($c) => $c->where('status', $status));
            }
        }
        if ($q = trim((string) $request->query('q'))) {
            $like = '%' . $q . '%';
            $query->where(function ($outer) use ($like) {
                $outer->whereHas('user', fn ($u) => $u->where('name', 'like', $like))
                    ->orWhereHas('listing', fn ($l) => $l->where('name', 'like', $like))
                    ->orWhereHas('activeConversation.users', fn ($u) => $u->where('users.name', 'like', $like));
            });
        }

        $perPage = (int) $request->query('per_page', 10);
        $chats = $query->latest()->paginate(min(max($perPage, 1), 50));

        // Single aggregate query — no per-row COUNT.
        $conversationIds = $chats->getCollection()
            ->map(fn ($chat) => $chat->activeConversation?->id)
            ->filter()
            ->values()
            ->all();

        if (!empty($conversationIds)) {
            $unreadByConversation = DB::table('messages')
                ->select('messages.conversation_id', DB::raw('COUNT(*) as unread'))
                ->join('conversation_users', function ($join) use ($user) {
                    $join->on('conversation_users.conversation_id', '=', 'messages.conversation_id')
                        ->where('conversation_users.user_id', '=', $user->id);
                })
                ->whereIn('messages.conversation_id', $conversationIds)
                ->where('messages.user_id', '!=', $user->id)
                ->whereIn('messages.status', ['active', 'updated'])
                ->where(function ($w) {
                    $w->whereNull('conversation_users.last_read_at')
                        ->orWhereColumn('messages.created_at', '>', 'conversation_users.last_read_at');
                })
                ->groupBy('messages.conversation_id')
                ->pluck('unread', 'messages.conversation_id');

            foreach ($chats as $chat) {
                $conv = $chat->activeConversation;
                if ($conv) {
                    $conv->setAttribute('computed_unread_count', (int) ($unreadByConversation[$conv->id] ?? 0));
                }
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

        $chat->load([
            'user',
            'listing.property',
            'conversations.latestMessage.user',
            'conversations.users',
            'activeConversation.agentUser',
            'activeConversation.users',
            'activeConversation.latestMessage.user',
        ]);

        // Compute unread for the active conversation so the show response
        // matches the list shape.
        $user = Auth::user();
        $conv = $chat->activeConversation;
        if ($conv) {
            $pivot = $conv->users->firstWhere('id', $user->id)?->pivot;
            $lastReadAt = $pivot?->last_read_at;
            $unreadQuery = $conv->messages()
                ->where('user_id', '!=', $user->id)
                ->whereIn('status', ['active', 'updated']);
            if ($lastReadAt) {
                $unreadQuery->where('created_at', '>', $lastReadAt);
            }
            $conv->setAttribute('computed_unread_count', $unreadQuery->count());
        }

        return new ChatResource($chat);
    }

    public function destroy(Chat $chat)
    {
        $this->authorize('delete', $chat);

        $chat->delete();

        return response()->json(['message' => 'Chat deleted.']);
    }
}
