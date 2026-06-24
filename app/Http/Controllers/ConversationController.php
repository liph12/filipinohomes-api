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

        if ($conversation->status !== 'pending') {
            return response()->json(['message' => 'Only pending conversations can be accepted.'], 422);
        }

        $this->applyAccept($conversation, Auth::user());

        return new ConversationResource($conversation->fresh([
            'latestMessage.user', 'users', 'reviewedBy',
        ]));
    }

    public function reject(Conversation $conversation)
    {
        $this->authorize('moderate', $conversation);

        if ($conversation->status !== 'pending') {
            return response()->json(['message' => 'Only pending conversations can be rejected.'], 422);
        }

        $this->applyReject($conversation, Auth::user());

        return new ConversationResource($conversation->fresh([
            'latestMessage.user', 'users', 'reviewedBy',
        ]));
    }

    /**
     * Per-conversation accept logic — shared between the single-row
     * accept() endpoint and the bulkAction() endpoint. Caller is
     * responsible for authorization + the status === 'pending' check;
     * this helper assumes both have already passed.
     *
     * Side effects: updates conversation status, attaches the agent
     * to conversation_users so they see the full message history,
     * and dispatches the acceptance email (with try/catch so a
     * failing transport never 500s the caller — the DB commit
     * already succeeded by the time we attempt the send).
     */
    private function applyAccept(Conversation $conversation, $user): void
    {
        $message = $conversation->latestMessage?->body;
        $agent = $conversation->agentUser;
        $sender = $conversation->chat?->user;
        // Load the sender's agent profile so the email can surface
        // their WhatsApp number when they're an agent. Skipped
        // silently for regular clients (the relation returns null).
        $sender?->loadMissing('agent');
        $type = $conversation->chat?->listing;
        // Load the relations the email's property card needs so we
        // don't trigger N+1 queries inside the mailer payload builder.
        if ($type) {
            $type->load([
                'agent',
                'category',
                'property.barangay.city.province',
                'property.propertyAttribute.subtype.type',
            ]);
        }
        // Slug trailer must be the chat_id — the frontend's
        // ListingInquiries component matches `{slug}-{chat.id}` to
        // find the right inquiry. Using conversation.id here would
        // render the page with no inquiry selected (slugError =
        // "invalid"). Falls through gracefully when the chat has
        // no listing (agent-direct chats don't carry a listing).
        $slug = $type
            ? Str::slug($type->name) . '-' . $conversation->chat_id
            : 'chat-' . $conversation->chat_id;

        $listingName = $type?->name;
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

        // Strictly notify the agent. Admins + team leader already
        // saw the submission email when the client first filed the
        // inquiry (see ChatController@store → dispatchForSubmission),
        // so a second copy here would just be inbox noise.
        //
        // The acceptance itself is already committed above — a
        // failing email transport MUST NOT 500 the controller. Log
        // the failure + write an audit row, then return success;
        // the email is a side-effect notification, not a critical
        // path. Particularly important for bulkAction() where one
        // bad email shouldn't take down a batch of 50.
        if (!$agent || !$sender) {
            return; // nothing to notify (e.g. agent_user_id was null)
        }
        try {
            MessageNotificationMailer::dispatchForAcceptance(
                sender:      $sender,
                agent:       $agent,
                message:     $message ?? '',
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
    }

    /**
     * Per-conversation reject logic — shared between the single-row
     * reject() endpoint and the bulkAction() endpoint. No email
     * dispatch on the reject side (clients aren't notified of
     * rejection by design).
     */
    private function applyReject(Conversation $conversation, $user): void
    {
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
    }

    /**
     * Moderator-only bulk Accept / Reject endpoint. Validates the
     * payload, then loops the conversation_ids array applying the
     * same per-row authorization + status check that the single-row
     * endpoints do. Each conversation gets its own audit row via
     * the existing LogsActivity trait — no separate bulk-audit
     * write needed.
     *
     * Returns a per-batch breakdown:
     *   { accepted: int, rejected: int,
     *     skipped: [{id, reason: 'not_pending'|'unauthorized'|'not_found'}, ...],
     *     errors:  [{id, message}, ...] }
     *
     * The endpoint NEVER aborts the whole batch — if one item is
     * unauthorized or non-pending, the others continue. The
     * frontend surfaces the skipped/errors counts in its toast.
     */
    public function bulkAction(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:accept,reject',
            'conversation_ids' => 'required|array|min:1|max:100',
            'conversation_ids.*' => 'integer|exists:conversations,id',
        ]);

        $action = $validated['action'];
        $user = Auth::user();

        $results = [
            'accepted' => 0,
            'rejected' => 0,
            'skipped'  => [],
            'errors'   => [],
        ];

        foreach ($validated['conversation_ids'] as $convId) {
            try {
                $conv = Conversation::find($convId);
                if (!$conv) {
                    $results['skipped'][] = ['id' => $convId, 'reason' => 'not_found'];
                    continue;
                }
                // Per-conversation authorization — same Policy gate
                // the single-row endpoints use. A TL trying to bulk
                // moderate a chat outside their team lands here as
                // 'unauthorized' instead of throwing 403.
                try {
                    $this->authorize('moderate', $conv);
                } catch (Throwable $e) {
                    $results['skipped'][] = ['id' => $convId, 'reason' => 'unauthorized'];
                    continue;
                }
                if ($conv->status !== 'pending') {
                    $results['skipped'][] = ['id' => $convId, 'reason' => 'not_pending'];
                    continue;
                }
                if ($action === 'accept') {
                    $this->applyAccept($conv, $user);
                    $results['accepted']++;
                } else {
                    $this->applyReject($conv, $user);
                    $results['rejected']++;
                }
            } catch (Throwable $e) {
                Log::warning('bulkAction item failed', [
                    'conversation_id' => $convId,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                $results['errors'][] = ['id' => $convId, 'message' => $e->getMessage()];
            }
        }

        return response()->json($results);
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

        $conversation->loadMissing('chat');
        $isChatOwner = (int) $conversation->chat?->user_id === (int) $user->id;
        $isAssignedAgent = (int) $conversation->agent_user_id === (int) $user->id;
        $isAgentDirectPeer = $conversation->chat?->type === 'agent'
            && (int) $conversation->chat?->type_id === (int) $user->id;

        // Stamp last_read_at. First-class participants (chat owner / assigned
        // agent / agent-direct peer) are attached if not already present.
        // Moderators (admin, team leader, browsing agent) are stamped ONLY
        // when they're already a participant (e.g. they once sent a message) —
        // we no longer auto-attach observers. syncWithoutDetaching had been
        // adding everyone who ever opened a thread, bloating conversation_users
        // to dozens of "participants" and fanning every realtime event out to
        // all of them. updateExistingPivot no-ops when there's no row.
        if ($isChatOwner || $isAssignedAgent || $isAgentDirectPeer) {
            $conversation->users()->syncWithoutDetaching([
                $user->id => ['last_read_at' => $now],
            ]);
        } else {
            $conversation->users()->updateExistingPivot($user->id, [
                'last_read_at' => $now,
            ]);
        }

        // messages.read_at is the public "seen" signal — visible to the
        // other party as the seen-receipt + avatar at the bottom of the
        // bubble. Only the two first-class participants (the chat-owning
        // client and the conversation's assigned agent) can flip it.
        // Admins, team leaders, and anyone else who happens to be in the
        // pivot stay invisible in the seen system so a client never
        // learns from the UI that a moderator is reading along.
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
