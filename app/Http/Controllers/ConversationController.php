<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Chat;
use App\Models\Conversation;
use App\Services\ReviewEligibilityService;
use App\Services\TeamLeadershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Mail\MessageNotificationMailer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $listingName = $conversation->chat?->listing?->name;
        $conversation->auditSource = 'inquiry_accept';
        $conversation->auditDescription = sprintf(
            '%s accepted the inquiry%s',
            $user->name,
            $listingName ? " on {$listingName}" : '',
        );

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

        // Strictly notify the agent. Admins + team leader already saw the
        // submission email when the client first filed the inquiry (see
        // ChatController@store → dispatchForSubmission), so a second copy
        // here would just be inbox noise.
        //
        // The acceptance itself is already committed to the DB above —
        // a failing email transport (SMTP down, disabled mailbox at the
        // provider, network blip) MUST NOT 500 the controller and make
        // the moderator think their click didn't work. Log the failure
        // and return success; the email is a side-effect notification,
        // not a critical path.
        try {
            MessageNotificationMailer::dispatchForAcceptance(
                sender:      $sender,
                agent:       $agent,
                message:     $message,
                slug:        $slug,
                listing:     MessageNotificationMailer::buildListingPayload($type),
                agentUserId: $conversation->agent_user_id,
            );
        } catch (Throwable $e) {
            Log::warning('Acceptance email failed to dispatch', [
                'conversation_id' => $conversation->id,
                'agent_user_id'   => $conversation->agent_user_id,
                'error'           => $e->getMessage(),
            ]);
            app(\App\Services\AuditMailService::class)->recordFailure(
                $e,
                MessageNotificationMailer::class,
                $agent?->email ? [$agent->email] : [],
                'Inquiry accepted — agent notification',
                [
                    'auditable_type' => Conversation::class,
                    'auditable_id'   => $conversation->id,
                ],
            );
        }

        return new ConversationResource($conversation);
    }

    public function reject(Conversation $conversation)
    {
        $this->authorize('moderate', $conversation);

        if ($conversation->status !== 'pending') {
            return response()->json(['message' => 'Only pending conversations can be rejected.'], 422);
        }

        $user = Auth::user();

        $listingName = $conversation->chat?->listing?->name;
        $conversation->auditSource = 'inquiry_reject';
        $conversation->auditDescription = sprintf(
            '%s rejected the inquiry%s',
            $user->name,
            $listingName ? " on {$listingName}" : '',
        );

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

        $user = Auth::user();
        $listingName = $conversation->chat?->listing?->name;
        $conversation->auditSource = 'inquiry_close';
        $conversation->auditDescription = sprintf(
            '%s closed the inquiry%s',
            $user?->name ?? 'Someone',
            $listingName ? " on {$listingName}" : '',
        );

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

        $user = Auth::user();
        $listingName = $conversation->chat?->listing?->name;
        $conversation->auditSource = 'inquiry_reopen';
        $conversation->auditDescription = sprintf(
            '%s reopened the inquiry%s',
            $user?->name ?? 'Someone',
            $listingName ? " on {$listingName}" : '',
        );

        $conversation->update([
            'status' => 'accepted',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        $conversation->load(['latestMessage.user', 'users']);

        return new ConversationResource($conversation);
    }

    /**
     * Rate-prompt eligibility probe. The frontend hits this whenever
     * the client opens a conversation; the response decides whether
     * the inline RateAgentInlineCard renders (and in Add vs. Edit
     * mode). Server-side enforcement also runs in
     * AgentReviewController::store so the answer here is advisory.
     */
    public function ratePromptEligibility(Conversation $conversation)
    {
        $this->authorize('view', $conversation);
        $service = app(ReviewEligibilityService::class);
        $clientId = (int) Auth::id();
        $eligibility = $service->check($clientId, $conversation);
        $submission = $service->canSubmit($clientId, $conversation);

        // Additive — keeps every existing field intact so older
        // frontend callers keep working unchanged. New can_submit /
        // submit_reason power the manual rate entries (chat-header
        // kebab, agent profile hero button).
        return response()->json(array_merge($eligibility, [
            'can_submit' => $submission['can_submit'],
            'submit_reason' => $submission['reason'],
        ]));
    }

    public function markRead(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $user = Auth::user();
        $now = now();

        // Pivot last_read_at is always stamped — moderators (admin / TL)
        // still need accurate unread counts in their own inbox. The
        // pivot is private per participant; it never leaks across users.
        $conversation->users()->syncWithoutDetaching([
            $user->id => ['last_read_at' => $now],
        ]);

        // messages.read_at is the public "seen" signal — visible to the
        // other party as the seen-receipt + avatar at the bottom of the
        // bubble. Only the two first-class participants (the chat-owning
        // client and the conversation's assigned agent) can flip it.
        // Admins, team leaders, and anyone else who happens to be in the
        // pivot stay invisible in the seen system so a client never
        // learns from the UI that a moderator is reading along.
        $conversation->loadMissing('chat');
        $isChatOwner = (int) $conversation->chat?->user_id === (int) $user->id;
        $isAssignedAgent = (int) $conversation->agent_user_id === (int) $user->id;

        if ($isChatOwner || $isAssignedAgent) {
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
