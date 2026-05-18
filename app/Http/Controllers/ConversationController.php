<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Chat;
use App\Models\Conversation;
use App\Services\TeamLeadershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Mail\MessageNotificationMailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

        // Agents should only see accepted/closed conversations, not pending ones.
        // Team leaders are an exception: they need to see pending inquiries for
        // their team members so they can moderate them.
        if ($user->role?->name === 'agent') {
            $ledIds = app(TeamLeadershipService::class)->getLedTeamMemberUserIds($user->id);

            $query->where(function ($q) use ($ledIds) {
                $q->whereIn('status', ['accepted', 'closed']);
                if (!empty($ledIds)) {
                    $q->orWhereIn('agent_user_id', $ledIds);
                }
            });
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
        $message = $conversation->latestMessage->body;
        $agent = $conversation->agentUser;
        $sender = $conversation->chat->user;
        // Load the sender's agent profile so the email can surface their
        // WhatsApp number when they're an agent. Skipped silently for
        // regular clients (the relation just returns null).
        $sender?->loadMissing('agent');
        $type = $conversation->chat->listing;
        // Load the relations the email's property card needs so we don't
        // trigger N+1 queries inside the mailer payload builder.
        if ($type) {
            $type->load([
                'agent',
                'category',
                'property.barangay.city.province',
                'property.propertyAttribute.subtype.type',
            ]);
        }
        // Slug trailer must be the chat_id — the frontend's ListingInquiries
        // component matches `{slug}-{chat.id}` to find the right inquiry.
        // Using conversation.id here would render the page with no inquiry
        // selected (slugError = "invalid").
        $slug = Str::slug($type->name)."-".$conversation->chat_id;

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
                $conversation->agent_user_id => [
                    'last_read_at' => null,
                    'last_notified_at' => now(),
                ],
            ]);
        }

        $conversation->load(['latestMessage.user', 'users', 'reviewedBy']);

        Mail::to($agent->email)->send(new MessageNotificationMailer(
            $sender,
            $agent,
            $message,
            $slug,
            'agent',
            MessageNotificationMailer::buildListingPayload($type),
            $conversation->agent_user_id,
        ));

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

    public function close(Conversation $conversation)
    {
        $this->authorize('close', $conversation);

        if ($conversation->status !== 'accepted') {
            return response()->json(['message' => 'Only accepted conversations can be closed.'], 422);
        }

        $conversation->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ]);

        $conversation->load(['latestMessage.user', 'users', 'closedBy']);

        return new ConversationResource($conversation);
    }

    public function reopen(Conversation $conversation)
    {
        $this->authorize('reopen', $conversation);

        if ($conversation->status !== 'closed') {
            return response()->json(['message' => 'Only closed conversations can be reopened.'], 422);
        }

        $conversation->update([
            'status' => 'accepted',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        $conversation->load(['latestMessage.user', 'users']);

        return new ConversationResource($conversation);
    }

    public function markRead(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $user = Auth::user();
        $now = now();

        // Ensure the user is a participant (admins may not be in agent-type chats)
        $conversation->users()->syncWithoutDetaching([
            $user->id => ['last_read_at' => $now],
        ]);

        // Admins should not trigger "seen" — only update pivot for unread tracking
        if ($user->role?->name !== 'admin') {
            $conversation->messages()
                ->where('user_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => $now]);
        }

        return response()->json([
            'message' => 'Marked as read.',
            'read_at' => $now->toISOString(),
        ]);
    }
}
