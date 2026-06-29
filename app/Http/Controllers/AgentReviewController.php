<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgentReviewResource;
use App\Models\AgentReview;
use App\Models\AgentReviewHelpfulVote;
use App\Models\AgentReviewResponse;
use App\Models\Conversation;
use App\Services\ReviewAntiAbuseService;
use App\Services\ReviewEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AgentReviewController extends Controller
{
    /**
     * Public — paginated visible reviews for an agent (by user_id).
     * Includes the agent's response when present. Optional filters:
     *   rating=4 (exact match), tag=responsiveness (single tag inclusion).
     */
    public function index(Request $request, int $agentUserId)
    {
        $validated = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'tag' => 'sometimes|string|max:32',
            'sort' => 'sometimes|in:recent,highest,lowest,with_comment,with_response',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $query = AgentReview::query()
            ->where('agent_user_id', $agentUserId)
            ->where('status', 'visible')
            ->with([
                // Display name + avatar for the row header.
                'client:id,name,avatar',
                // role drives the "Inquired as agent" badge — eager-load
                // it via the client relation so the resource projection
                // can read $this->client->role.
                'client.role:id,name',
                // conversation → chat → listing powers the "Re: {listing}"
                // chip per row. Chats reference listings via the
                // polymorphic-ish (type, type_id) pair — listing() uses
                // type_id as the FK so we need to select that column.
                'conversation:id,chat_id',
                'conversation.chat:id,type,type_id',
                'conversation.chat.listing:id,name,slug,featured_photo',
                'response',
                // helpfulVotes minimal selects — only need user_id so the
                // resource can compute is_helpful_for_me without N+1.
                'helpfulVotes:id,agent_review_id,user_id',
            ]);

        if (!empty($validated['rating'])) {
            $query->where('overall_rating', (int) $validated['rating']);
        }
        if (!empty($validated['tag'])) {
            // JSON_CONTAINS for MySQL; the column stores a JSON array.
            $query->whereJsonContains('tags', $validated['tag']);
        }

        // Sort dispatch — default 'recent'. with_comment / with_response
        // double as filters because the surfaces they target are
        // implicit ("show me reviews with comments / replies").
        match ($validated['sort'] ?? 'recent') {
            'highest' => $query
                ->orderByDesc('overall_rating')
                ->orderByDesc('created_at'),
            'lowest' => $query
                ->orderBy('overall_rating')
                ->orderByDesc('created_at'),
            'with_comment' => $query
                ->whereNotNull('comment')
                ->where('comment', '!=', '')
                ->latest(),
            'with_response' => $query
                ->whereHas('response')
                ->latest(),
            default => $query->latest(),
        };

        return AgentReviewResource::collection(
            $query->paginate($validated['per_page'] ?? 10)
        );
    }

    /**
     * Aggregate stats for an agent's review section — used by the
     * frontend AgentReviewsSection header. Counts grouped by star.
     *
     * Aggregates count only client-authored reviews. Agent-to-agent
     * and admin-authored reviews still surface in the list (with the
     * "Inquired as agent" badge) but never affect the headline avg /
     * total / breakdown / tag frequency. AgentReviewsSection renders
     * a tooltip next to the totals explaining the policy.
     */
    public function summary(int $agentUserId)
    {
        // Shared scope helper — applied to both the breakdown query and
        // the tag-frequency pull so the two never drift apart.
        $clientOnly = function ($query) use ($agentUserId) {
            $query
                ->from('agent_reviews as r')
                ->join('users as u', 'u.id', '=', 'r.client_user_id')
                ->leftJoin('roles as ro', 'ro.id', '=', 'u.role_id')
                ->where('r.agent_user_id', $agentUserId)
                ->where('r.status', 'visible')
                ->where('ro.name', 'client');
        };

        $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $rows = DB::table('agent_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.client_user_id')
            ->leftJoin('roles as ro', 'ro.id', '=', 'u.role_id')
            ->where('r.agent_user_id', $agentUserId)
            ->where('r.status', 'visible')
            ->where('ro.name', 'client')
            ->selectRaw('r.overall_rating, COUNT(*) as n')
            ->groupBy('r.overall_rating')
            ->pluck('n', 'overall_rating');
        foreach ($rows as $star => $count) {
            $breakdown[(int) $star] = (int) $count;
        }
        $total = array_sum($breakdown);
        $avg = $total > 0
            ? round(
                (5 * $breakdown[5] + 4 * $breakdown[4] + 3 * $breakdown[3]
                  + 2 * $breakdown[2] + 1 * $breakdown[1]) / $total,
                2
            )
            : null;

        // Tag frequency over CLIENT reviews only (matches the breakdown
        // above). Bounded by the agent's visible-client-review count so
        // unpacking in PHP stays cheap.
        $allTagsRaw = DB::table('agent_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.client_user_id')
            ->leftJoin('roles as ro', 'ro.id', '=', 'u.role_id')
            ->where('r.agent_user_id', $agentUserId)
            ->where('r.status', 'visible')
            ->where('ro.name', 'client')
            ->whereNotNull('r.tags')
            ->pluck('r.tags')
            ->toArray();
        $allTags = [];
        foreach ($allTagsRaw as $tagJson) {
            $decoded = json_decode((string) $tagJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $tag) {
                    if (is_string($tag) && $tag !== '') $allTags[] = $tag;
                }
            }
        }
        $tagCounts = array_count_values($allTags);
        arsort($tagCounts);

        return response()->json([
            'avg_rating' => $avg,
            'total_reviews' => $total,
            'breakdown' => $breakdown,
            'top_tags' => (object) $tagCounts,
        ]);
    }

    /**
     * Create or upsert the current viewer's review for an agent. The
     * unique (client_user_id, agent_user_id) constraint guarantees one
     * row per pair; we explicitly upsert here so the response shape is
     * always the same whether this was a first review or an edit.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'agent_user_id' => 'required|integer|exists:users,id',
            'conversation_id' => 'required|integer|exists:conversations,id',
            'overall_rating' => 'required|integer|min:1|max:5',
            'tags' => 'sometimes|array|max:8',
            'tags.*' => 'string|max:32',
            'comment' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        $clientId = (int) $user->id;
        $agentId = (int) $validated['agent_user_id'];

        if ($clientId === $agentId) {
            abort(422, 'You cannot rate yourself.');
        }

        $conv = Conversation::with('chat')->findOrFail($validated['conversation_id']);
        // Submission uses the looser canSubmit() gate — manual entries
        // (chat-header kebab, agent profile button) intentionally bypass
        // the strict auto-prompt gates so a client who knows they want
        // to rate doesn't have to wait for the conversation to go quiet.
        // The strict check() still drives WHERE we surface the prompt.
        $submission = app(ReviewEligibilityService::class)->canSubmit($clientId, $conv);
        if (!$submission['can_submit']) {
            abort(422, $submission['reason'] ?? 'You are not eligible to rate this agent yet.');
        }

        $status = app(ReviewAntiAbuseService::class)->initialStatus($clientId, $agentId);

        $review = AgentReview::updateOrCreate(
            [
                'client_user_id' => $clientId,
                'agent_user_id' => $agentId,
            ],
            [
                'conversation_id' => (int) $validated['conversation_id'],
                'overall_rating' => (int) $validated['overall_rating'],
                'tags' => $validated['tags'] ?? [],
                'comment' => $validated['comment'] ?? null,
                'status' => $status,
                // Re-edit resets the window. Locked thereafter on update().
                'edit_window_ends_at' => now()->addDays(7),
                // Reset moderator suppression on resubmit so a previously
                // hidden review re-enters the pipeline after the client
                // genuinely edits it. Anti-abuse re-runs above.
                'hidden_by' => null,
                'hidden_at' => null,
                'hidden_reason' => null,
            ]
        );

        // Seal the pivot so the inline chat card doesn't re-render, and clear
        // any pending agent "update" request now that the client has (re)rated.
        DB::table('conversation_users')
            ->where('conversation_id', $conv->id)
            ->where('user_id', $clientId)
            ->update([
                'rate_prompt_shown_at' => now(),
                'rate_prompt_requested_at' => null,
            ]);

        $review->load(['client:id,name,avatar', 'response']);

        return new AgentReviewResource($review);
    }

    /**
     * Edit an existing review. Owner only; locked after the 7-day
     * edit window. Tags / comment / rating can all change. Anti-abuse
     * re-runs in case the edit introduces flagged content.
     */
    public function update(Request $request, AgentReview $review)
    {
        $user = Auth::user();
        if ((int) $review->client_user_id !== (int) $user->id) {
            abort(403, 'You can only edit your own review.');
        }
        if ($review->edit_window_ends_at && now()->greaterThan($review->edit_window_ends_at)) {
            abort(422, 'Edit window expired.');
        }

        $validated = $request->validate([
            'overall_rating' => 'sometimes|integer|min:1|max:5',
            'tags' => 'sometimes|array|max:8',
            'tags.*' => 'string|max:32',
            'comment' => 'nullable|string|max:1000',
        ]);

        $status = app(ReviewAntiAbuseService::class)
            ->initialStatus((int) $review->client_user_id, (int) $review->agent_user_id);

        $review->fill(array_filter($validated, fn ($v) => $v !== null));
        $review->status = $status;
        $review->edit_window_ends_at = now()->addDays(7);
        // Clear any prior moderator suppression — the client's edit
        // brings the row back through the same pipeline.
        $review->hidden_by = null;
        $review->hidden_at = null;
        $review->hidden_reason = null;
        $review->save();

        $review->load(['client:id,name,avatar', 'response']);

        return new AgentReviewResource($review);
    }

    /**
     * Owner withdraws their review. Cascades the response via the
     * foreign-key constraint. Rollup recomputes via the model hook.
     */
    public function destroy(AgentReview $review)
    {
        $user = Auth::user();
        if ((int) $review->client_user_id !== (int) $user->id) {
            abort(403, 'You can only delete your own review.');
        }
        $review->delete();
        return response()->json(['message' => 'Review removed.']);
    }

    /**
     * Agent's right of reply. One response per review (enforced by the
     * unique constraint on agent_review_responses.agent_review_id);
     * resubmitting overwrites the previous body.
     */
    public function storeResponse(Request $request, AgentReview $review)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $user = Auth::user();
        if ((int) $review->agent_user_id !== (int) $user->id) {
            abort(403, 'Only the rated agent can respond to this review.');
        }

        $response = AgentReviewResponse::updateOrCreate(
            ['agent_review_id' => $review->id],
            [
                'agent_user_id' => (int) $review->agent_user_id,
                'body' => trim($validated['body']),
            ]
        );

        $review->load(['client:id,name,avatar', 'response']);

        return new AgentReviewResource($review);
    }

    /**
     * Remove the agent's public response from a review. Same auth gate
     * as storeResponse — only the rated agent themselves can clear
     * their reply. The review row stays; only the linked response
     * record is dropped.
     */
    public function destroyResponse(AgentReview $review)
    {
        $user = Auth::user();
        if ((int) $review->agent_user_id !== (int) $user->id) {
            abort(403, 'Only the rated agent can remove this response.');
        }

        AgentReviewResponse::where('agent_review_id', $review->id)->delete();

        $review->load(['client:id,name,avatar', 'response']);

        return new AgentReviewResource($review);
    }

    /**
     * Admin moderation — flip status to visible / hidden / flagged.
     * Records who hid it + when + why so admins can audit the trail.
     * Rollup recomputes via the model hook when status changes.
     */
    public function setVisibility(Request $request, AgentReview $review)
    {
        $user = Auth::user();
        if ($user->role?->name !== 'admin') {
            abort(403, 'Only administrators can change review visibility.');
        }

        $validated = $request->validate([
            'status' => 'required|in:visible,hidden,flagged',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validated['status'] === 'visible') {
            $review->status = 'visible';
            $review->hidden_by = null;
            $review->hidden_at = null;
            $review->hidden_reason = null;
        } else {
            $review->status = $validated['status'];
            $review->hidden_by = (int) $user->id;
            $review->hidden_at = now();
            $review->hidden_reason = $validated['reason'] ?? null;
        }
        $review->save();

        $review->load(['client:id,name,avatar', 'response']);

        return new AgentReviewResource($review);
    }

    /**
     * Admin index — paginated, filterable. Used by /admin/agent-feedback.
     */
    public function adminIndex(Request $request)
    {
        $user = Auth::user();
        if ($user->role?->name !== 'admin') {
            abort(403, 'Admin only.');
        }

        $validated = $request->validate([
            'agent_user_id' => 'sometimes|integer|exists:users,id',
            'status' => 'sometimes|in:visible,hidden,flagged',
            'rating' => 'sometimes|integer|min:1|max:5',
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $q = AgentReview::query()
            ->with([
                'client:id,name,avatar',
                'agent:id,name,avatar',
                // Pull the agent's active team_members + their team so the
                // resource can project agent.team_name. team_agents.status
                // is filtered by AgentReviewResource via firstWhere.
                'agent.agent:id,user_id',
                'agent.agent.teamMembers' => fn ($q) => $q->where('status', 'active'),
                'agent.agent.teamMembers.team:id,name',
                'response',
                'hiddenByUser:id,name',
                // chat_id powers each row's "view conversation" deep-link.
                'conversation:id,chat_id',
            ])
            ->latest();

        if (!empty($validated['agent_user_id'])) {
            $q->where('agent_user_id', $validated['agent_user_id']);
        }
        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }
        if (!empty($validated['rating'])) {
            $q->where('overall_rating', (int) $validated['rating']);
        }
        if (!empty($validated['search'])) {
            $term = '%' . $validated['search'] . '%';
            $q->where(function ($qq) use ($term) {
                $qq->whereHas('client', fn ($c) => $c->where('name', 'like', $term))
                   ->orWhereHas('agent', fn ($a) => $a->where('name', 'like', $term))
                   ->orWhere('comment', 'like', $term);
            });
        }

        return AgentReviewResource::collection(
            $q->paginate($validated['per_page'] ?? 20)
        );
    }

    /**
     * Aggregate stats for the admin dashboard header — platform totals
     * and the flagged-rate signal.
     */
    public function adminSummary(Request $request)
    {
        $user = Auth::user();
        if ($user->role?->name !== 'admin') {
            abort(403, 'Admin only.');
        }

        $byStatus = AgentReview::query()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $visible = (int) ($byStatus['visible'] ?? 0);
        $hidden = (int) ($byStatus['hidden'] ?? 0);
        $flagged = (int) ($byStatus['flagged'] ?? 0);
        $total = $visible + $hidden + $flagged;

        $platformAvg = AgentReview::query()
            ->where('status', 'visible')
            ->avg('overall_rating');

        return response()->json([
            'total' => $total,
            'visible' => $visible,
            'hidden' => $hidden,
            'flagged' => $flagged,
            'platform_avg_rating' => $platformAvg !== null ? round((float) $platformAvg, 2) : null,
            'flagged_pct' => $total > 0 ? round(($flagged / $total) * 100, 1) : 0.0,
        ]);
    }

    /**
     * Client dismissed the inline rate card on a conversation. Records
     * the pivot timestamp so the card stays hidden across sessions.
     */
    public function dismissPrompt(Conversation $conversation)
    {
        $user = Auth::user();
        DB::table('conversation_users')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['rate_prompt_shown_at' => now()]);

        return response()->json(['message' => 'Rate prompt dismissed.']);
    }

    /**
     * The assigned agent (or a moderator) asks the inquiring client to
     * leave a review. Stamps the client's conversation_users row so the
     * eligibility probe returns agent_requested=true, surfacing the inline
     * rate prompt in the client's thread. Authorized via the conversation
     * 'moderate' policy — assigned agent, their team leader, or admin.
     */
    public function requestReview(Conversation $conversation)
    {
        $this->authorize('moderate', $conversation);
        $conversation->loadMissing('chat');

        $clientUserId = $conversation->chat?->user_id;
        if (!$clientUserId) {
            return response()->json(
                ['message' => 'This conversation has no client to ask.'],
                422,
            );
        }
        if ((int) $clientUserId === (int) $conversation->agent_user_id) {
            return response()->json(
                ['message' => 'You cannot request a review from yourself.'],
                422,
            );
        }

        // Agents may only nudge an UPDATE to an existing review — never
        // solicit a fresh rating (that would be review-gating). Require a
        // review to exist; the client's thread prompt then opens in edit mode.
        $hasReview = AgentReview::where('client_user_id', $clientUserId)
            ->where('agent_user_id', $conversation->agent_user_id)
            ->exists();
        if (!$hasReview) {
            return response()->json(
                ['message' => 'This client has not left a review yet — there is nothing to update.'],
                422,
            );
        }

        DB::table('conversation_users')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $clientUserId)
            ->update(['rate_prompt_requested_at' => now()]);

        return response()->json(['message' => 'Update requested.']);
    }

    /**
     * The chat owner's review of the assigned agent for this conversation,
     * if any — as a full AgentReviewResource (or null). Authorized via the
     * conversation 'view' policy so EVERY viewer can read it: the client
     * (to prefill their edit form with the real previous data) and the
     * assigned agent / moderators (to see the persisted review in-thread).
     * is_own_review / is_editable_for_me are computed per the viewer, so
     * only the client gets edit affordances.
     */
    public function clientReview(Conversation $conversation)
    {
        $this->authorize('view', $conversation);
        $conversation->loadMissing('chat');

        $clientUserId = $conversation->chat?->user_id;
        if (!$clientUserId || !$conversation->agent_user_id) {
            return response()->json(['review' => null]);
        }

        $review = AgentReview::where('client_user_id', $clientUserId)
            ->where('agent_user_id', $conversation->agent_user_id)
            ->with(['client:id,name,avatar', 'response'])
            ->first();

        return response()->json([
            'review' => $review ? new AgentReviewResource($review) : null,
        ]);
    }

    /**
     * Batched eligibility lookup for the inquiry list. Returns every
     * chat owned by the caller whose active conversation is currently
     * rating-eligible AND hasn't been dismissed. Frontend uses this to
     * paint per-row "Rate" chips + the top-of-list banner count without
     * running N eligibility probes (one per row).
     */
    public function myEligibleInquiries(Request $request)
    {
        $user = Auth::user();
        $userId = (int) $user->id;
        $service = app(ReviewEligibilityService::class);

        // Only the chat owner can rate the agent on that chat. Limit
        // the scan to their own chats with an active conversation that
        // has an assigned agent. listing.property is needed for the
        // listing-status trigger so we eager-load it here too.
        $chats = \App\Models\Chat::query()
            ->where('user_id', $userId)
            ->whereHas('activeConversation', function ($q) {
                $q->whereNotNull('agent_user_id');
            })
            ->with([
                // activeConversation uses Eloquent's latestOfMany scope,
                // which joins conversations against itself. A slim
                // select with bare column names hits "ambiguous chat_id"
                // because both sides of the join expose it — use a
                // closure to qualify the columns to the base table.
                'activeConversation' => fn ($q) => $q->select([
                    'conversations.id',
                    'conversations.chat_id',
                    'conversations.status',
                    'conversations.agent_user_id',
                ]),
                'activeConversation.agentUser:id,name,avatar',
                'listing:id,property_id',
                'listing.property:id,status',
            ])
            ->get();

        $out = [];
        foreach ($chats as $chat) {
            $conv = $chat->activeConversation;
            if (!$conv) {
                continue;
            }
            // The service expects chat.listing.property reachable from
            // $conv. The relations above feed chat → listing → property,
            // but the service looks at $conv->chat → listing → property,
            // so wire conv.chat to the already-loaded $chat to avoid a
            // duplicate query.
            $conv->setRelation('chat', $chat);

            $elig = $service->check($userId, $conv);
            if (!$elig['eligible'] || $elig['already_shown']) {
                continue;
            }

            $out[] = [
                'chat_id' => (int) $chat->id,
                'conversation_id' => (int) $conv->id,
                'agent_user_id' => (int) $conv->agent_user_id,
                'agent_name' => $conv->agentUser?->name,
                'agent_avatar' => $conv->agentUser?->avatar,
                'existing_review_id' => $elig['existing_review_id'],
                'reason_code' => $elig['reason_code'],
            ];
        }

        return response()->json(['data' => $out]);
    }

    /**
     * Toggle the caller's "helpful" vote on a review. One vote per
     * (review, user) — submitting twice removes the vote. The
     * agent_reviews.helpful_count counter is kept in sync so public
     * list reads don't aggregate the votes table.
     *
     * Gating:
     *   - Auth required (sanctum).
     *   - The review must be visible (hidden / flagged rows aren't
     *     publicly displayed, so accepting votes on them would create
     *     stale counts when the review re-surfaces).
     *   - The reviewer can't mark their own review helpful.
     */
    public function toggleHelpful(AgentReview $review)
    {
        $user = Auth::user();
        $userId = (int) $user->id;

        if ($review->status !== 'visible') {
            abort(403, 'This review is not currently public.');
        }
        if ((int) $review->client_user_id === $userId) {
            abort(422, 'You cannot mark your own review as helpful.');
        }

        $existing = AgentReviewHelpfulVote::where('agent_review_id', $review->id)
            ->where('user_id', $userId)
            ->first();

        DB::transaction(function () use ($existing, $review, $userId, &$isHelpful) {
            if ($existing) {
                $existing->delete();
                // decrement, never go negative
                $review->decrement('helpful_count');
                if ($review->helpful_count < 0) {
                    $review->update(['helpful_count' => 0]);
                }
                $isHelpful = false;
            } else {
                AgentReviewHelpfulVote::create([
                    'agent_review_id' => $review->id,
                    'user_id' => $userId,
                ]);
                $review->increment('helpful_count');
                $isHelpful = true;
            }
        });

        return response()->json([
            'helpful_count' => (int) $review->fresh()->helpful_count,
            'is_helpful_for_me' => $isHelpful ?? false,
        ]);
    }

    /**
     * Manual-entry probe for the agent profile "Rate this Agent"
     * button. Locates the caller's most recent listing-chat
     * conversation with this agent and asks canSubmit() whether it
     * passes the relaxed manual gate. Used by AgentDetailPage to
     * decide whether to render the hero Rate button + which
     * conversation to attach the review to.
     */
    public function canSubmitForAgent(Request $request, int $agentUserId)
    {
        $user = Auth::user();
        $clientId = (int) $user->id;

        if ($clientId === $agentUserId) {
            return response()->json([
                'can_submit' => false,
                'reason' => 'You cannot rate yourself.',
                'conversation_id' => null,
                'existing_review_id' => null,
                'agent_user_id' => $agentUserId,
                'agent_name' => null,
                'agent_avatar' => null,
            ]);
        }

        // Pick the latest listing-chat where the caller owns the chat
        // and the active conversation is assigned to this agent. The
        // chat-owner constraint mirrors what canSubmit() enforces, so
        // we won't surprise the client with a 422 on submit.
        $conv = Conversation::query()
            ->whereHas('chat', function ($q) use ($clientId) {
                $q->where('user_id', $clientId);
            })
            ->where('agent_user_id', $agentUserId)
            ->whereIn('status', ['accepted', 'closed'])
            ->with(['chat', 'agentUser:id,name,avatar'])
            ->latest()
            ->first();

        if (!$conv) {
            return response()->json([
                'can_submit' => false,
                'reason' => 'No inquiry with this agent yet.',
                'conversation_id' => null,
                'existing_review_id' => null,
                'agent_user_id' => $agentUserId,
                'agent_name' => null,
                'agent_avatar' => null,
            ]);
        }

        $service = app(ReviewEligibilityService::class);
        $submission = $service->canSubmit($clientId, $conv);

        return response()->json([
            'can_submit' => $submission['can_submit'],
            'reason' => $submission['reason'],
            'conversation_id' => (int) $conv->id,
            'existing_review_id' => $submission['existing_review_id'],
            'agent_user_id' => $agentUserId,
            'agent_name' => $conv->agentUser?->name,
            'agent_avatar' => $conv->agentUser?->avatar,
        ]);
    }

    /**
     * Paginated history of reviews authored by the caller. Powers the
     * /client/my-reviews page. Returns rows of EVERY status (visible /
     * hidden / flagged) — the author is always allowed to see what
     * they themselves wrote, including admin-suppressed content, so
     * moderation never silently erases their voice. Public profile
     * reads still respect the visible-only filter via index().
     */
    public function mine(Request $request)
    {
        $user = Auth::user();
        $userId = (int) $user->id;

        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:30',
            'status' => 'sometimes|in:visible,hidden,flagged',
        ]);

        $q = AgentReview::query()
            ->where('client_user_id', $userId)
            ->latest()
            ->with([
                'agent:id,name,avatar',
                // teamMembers (active only) → team for the row's team chip.
                // Same eager-load pattern adminIndex uses so the resource's
                // agent.team_name projection populates.
                'agent.agent:id,user_id',
                'agent.agent.teamMembers' => fn ($qq) => $qq->where('status', 'active'),
                'agent.agent.teamMembers.team:id,name',
                'response',
                'hiddenByUser:id,name',
            ]);

        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        return AgentReviewResource::collection(
            $q->paginate($validated['per_page'] ?? 10)
        );
    }

    /**
     * Summary stats for the /client/my-reviews header strip. Returns
     * total / avg rating given / last review timestamp / top three
     * tags the caller picks most often. All scoped to the caller's
     * authored reviews regardless of status (so a client who's been
     * heavily moderated still sees the truth about their own history).
     */
    public function mineSummary(Request $request)
    {
        $user = Auth::user();
        $userId = (int) $user->id;

        $base = AgentReview::where('client_user_id', $userId);

        $total = (clone $base)->count();
        $avg = (clone $base)->avg('overall_rating');
        $latest = (clone $base)->max('created_at');

        // Tag frequency — pull the JSON arrays in one query, count in PHP.
        // The result set is bounded by the caller's own review count
        // (typically under a few dozen rows), so unpacking client-side
        // is cheaper than JSON_TABLE / generator-table acrobatics.
        $allTags = (clone $base)
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten(1)
            ->filter()
            ->all();
        $counts = array_count_values(array_map('strval', $allTags));
        arsort($counts);
        $topTags = array_slice($counts, 0, 3, preserve_keys: true);

        return response()->json([
            'total' => $total,
            'avg_rating_given' => $avg !== null ? round((float) $avg, 2) : null,
            'last_reviewed_at' => $latest,
            'top_tags' => (object) $topTags,
        ]);
    }

    /**
     * Leaderboards for /admin/agent-feedback. Returns:
     *   per_tag — top 5 agents per tag (responsiveness / knowledge /
     *             professionalism / helpfulness) ordered by avg rating.
     *   top_by_total — top 5 agents by total visible reviews.
     *   top_by_rating — top 5 agents by avg rating, gated at >=3 reviews
     *                   so a single 5.0 doesn't outrank battle-tested 4.6.
     *
     * Each row includes the agent's display name + active team so the
     * admin UI never has to surface "Agent #6".
     */
    public function leaderboards(Request $request)
    {
        $user = Auth::user();
        if ($user->role?->name !== 'admin') {
            abort(403, 'Admin only.');
        }

        $tags = ['responsiveness', 'knowledge', 'professionalism', 'helpfulness'];
        $perTag = [];
        foreach ($tags as $tag) {
            $perTag[$tag] = $this->aggregateAgents(function ($q) use ($tag) {
                // `r` is the agent_reviews alias inside aggregateAgents;
                // whereJsonContains doesn't honor the alias so use
                // whereRaw + JSON_CONTAINS directly. Bound value must
                // be JSON-encoded so MySQL matches the array element.
                $q->whereRaw('JSON_CONTAINS(r.tags, ?)', [json_encode($tag)]);
            }, orderBy: 'avg_rating_desc', limit: 5, minReviews: 1);
        }

        $topByTotal = $this->aggregateAgents(
            fn ($q) => $q,
            orderBy: 'total_desc',
            limit: 5,
            minReviews: 1,
        );

        $topByRating = $this->aggregateAgents(
            fn ($q) => $q,
            orderBy: 'avg_rating_desc',
            limit: 5,
            minReviews: 3,
        );

        return response()->json([
            'per_tag' => $perTag,
            'top_by_total' => $topByTotal,
            'top_by_rating' => $topByRating,
        ]);
    }

    /**
     * Per-team rollup. Group visible reviews by the agent's active team
     * (via agents.user_id → team_agents.agent_id → teams) and return
     * total_reviews + avg_rating + agent_count + leader_name. Mirrors
     * ChatController::stats' per_team shape so the admin UI is
     * visually consistent.
     */
    public function teamsRollup(Request $request)
    {
        $user = Auth::user();
        if ($user->role?->name !== 'admin') {
            abort(403, 'Admin only.');
        }

        // Team rollup matches the public-aggregate policy: only count
        // reviews authored by users with role.name='client'. Agent-to-
        // agent reviews appear in the per-agent list with the
        // "Inquired as agent" badge but never affect team rankings.
        $rows = DB::table('agent_reviews as r')
            ->join('users as cu', 'cu.id', '=', 'r.client_user_id')
            ->leftJoin('roles as cro', 'cro.id', '=', 'cu.role_id')
            ->join('agents as a', 'a.user_id', '=', 'r.agent_user_id')
            ->join('team_agents as ta', function ($j) {
                $j->on('ta.agent_id', '=', 'a.id')
                  ->where('ta.status', '=', 'active');
            })
            ->join('teams as t', 't.id', '=', 'ta.team_id')
            ->where('r.status', 'visible')
            ->where('cro.name', 'client')
            ->groupBy('t.id', 't.name')
            ->orderByRaw('AVG(r.overall_rating) DESC')
            ->select(
                't.id as team_id',
                't.name as team_name',
                DB::raw('COUNT(*) as total_reviews'),
                DB::raw('ROUND(AVG(r.overall_rating), 2) as avg_rating'),
                DB::raw('COUNT(DISTINCT r.agent_user_id) as rated_agent_count'),
            )
            ->get();

        // Enrich with leader name + total agent count using the same
        // join chain ChatController::stats uses for the per_team meta.
        $teamMeta = DB::table('teams as t')
            ->leftJoin('team_agents as la', function ($j) {
                $j->on('la.team_id', '=', 't.id')
                  ->where('la.status', '=', 'active')
                  ->where('la.is_leader', '=', true);
            })
            ->leftJoin('agents as la_a', 'la_a.id', '=', 'la.agent_id')
            ->leftJoin('users as la_u', 'la_u.id', '=', 'la_a.user_id')
            ->leftJoin('team_agents as ag', function ($j) {
                $j->on('ag.team_id', '=', 't.id')
                  ->where('ag.status', '=', 'active');
            })
            ->whereIn('t.id', $rows->pluck('team_id'))
            ->groupBy('t.id', 'la_u.name')
            ->select(
                't.id as team_id',
                'la_u.name as leader_name',
                DB::raw('COUNT(DISTINCT ag.agent_id) as agent_count'),
            )
            ->get()
            ->keyBy('team_id');

        $data = $rows->map(function ($r) use ($teamMeta) {
            $meta = $teamMeta[$r->team_id] ?? null;
            return [
                'team_id' => (int) $r->team_id,
                'team_name' => (string) $r->team_name,
                'leader_name' => $meta?->leader_name,
                'agent_count' => (int) ($meta?->agent_count ?? 0),
                'rated_agent_count' => (int) $r->rated_agent_count,
                'total_reviews' => (int) $r->total_reviews,
                'avg_rating' => (float) $r->avg_rating,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * Top reviewers (clients) ranked by visible review count. The avg
     * rating they GIVE is included so admins can spot retaliatory
     * patterns (one client with many low ratings is a flag).
     */
    public function topReviewers(Request $request)
    {
        $user = Auth::user();
        if ($user->role?->name !== 'admin') {
            abort(403, 'Admin only.');
        }

        $rows = DB::table('agent_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.client_user_id')
            ->where('r.status', 'visible')
            ->groupBy('r.client_user_id', 'u.name', 'u.avatar')
            ->orderByRaw('COUNT(*) DESC, AVG(r.overall_rating) ASC')
            ->limit(20)
            ->select(
                'r.client_user_id',
                'u.name',
                'u.avatar',
                DB::raw('COUNT(*) as reviews_count'),
                DB::raw('ROUND(AVG(r.overall_rating), 2) as avg_rating_given'),
            )
            ->get()
            ->map(fn ($r) => [
                'client_user_id' => (int) $r->client_user_id,
                'name' => $r->name,
                'avatar' => $r->avatar,
                'reviews_count' => (int) $r->reviews_count,
                'avg_rating_given' => (float) $r->avg_rating_given,
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * 30-day trend for the Overview tab sparkline. Returns one bucket
     * per calendar day (count + avg) plus headline numbers. Days with
     * no reviews appear with count=0, avg=null so the sparkline draws
     * a continuous line.
     */
    public function trends(Request $request)
    {
        $user = Auth::user();
        if ($user->role?->name !== 'admin') {
            abort(403, 'Admin only.');
        }

        $start = now()->subDays(29)->startOfDay();

        $rows = DB::table('agent_reviews')
            ->where('status', 'visible')
            ->where('created_at', '>=', $start)
            ->selectRaw("DATE(created_at) as d, COUNT(*) as n, ROUND(AVG(overall_rating), 2) as avg_r")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $daily = [];
        $totalCount = 0;
        $weightedSum = 0.0;
        for ($i = 0; $i < 30; $i++) {
            $date = now()->subDays(29 - $i)->toDateString();
            $row = $rows[$date] ?? null;
            $count = $row ? (int) $row->n : 0;
            $avg = $row ? (float) $row->avg_r : null;
            $daily[] = ['date' => $date, 'count' => $count, 'avg' => $avg];
            $totalCount += $count;
            if ($avg !== null) {
                $weightedSum += $avg * $count;
            }
        }

        return response()->json([
            'daily' => $daily,
            'total_30d' => $totalCount,
            'avg_30d' => $totalCount > 0
                ? round($weightedSum / $totalCount, 2)
                : null,
        ]);
    }

    /**
     * Agent-facing leaderboard. Unlike leaderboards()/teamsRollup()/trends()
     * (admin-only, full board + moderation context), this is open to any
     * authenticated agent and returns ONLY public-safe aggregate rows plus
     * the caller's own rank. Reuses the public-aggregate policy verbatim:
     * visible reviews authored by a role.name='client' user.
     *
     * Ranking is computed without window functions (portable to MySQL 5.7):
     * the caller's rank is the count of qualifying agents who outrank them,
     * plus one. Gated at >=3 reviews like the admin top_by_rating board, so a
     * lone 5.0 doesn't outrank a battle-tested 4.6; agents below the gate are
     * returned unranked (rank = null) with their raw stats.
     */
    public function agentLeaderboard(Request $request)
    {
        $user = Auth::user();
        $minReviews = 3;
        $limit = 10;

        // Top board — public-safe rows, ordered by avg then volume. Position
        // in this ordered slice is the displayed rank (1..N).
        $top = DB::query()
            ->fromSub($this->qualifyingAgentsSub($minReviews), 's')
            ->orderByRaw('s.avg_rating DESC, s.total_reviews DESC')
            ->limit($limit)
            ->get()
            ->values()
            ->map(fn ($r, $i) => $this->leaderRow($r, $i + 1))
            ->all();

        $total = DB::query()
            ->fromSub($this->qualifyingAgentsSub($minReviews), 's')
            ->count();

        // The caller's own qualifying row (null if below the review gate).
        $mine = DB::query()
            ->fromSub($this->qualifyingAgentsSub($minReviews), 's')
            ->where('s.agent_user_id', $user->id)
            ->first();

        if ($mine) {
            $ahead = DB::query()
                ->fromSub($this->qualifyingAgentsSub($minReviews), 's')
                ->where(function ($q) use ($mine) {
                    $q->where('s.avg_rating', '>', $mine->avg_rating)
                      ->orWhere(function ($q2) use ($mine) {
                          $q2->where('s.avg_rating', $mine->avg_rating)
                             ->where('s.total_reviews', '>', $mine->total_reviews);
                      });
                })
                ->count();
            $me = $this->leaderRow($mine, $ahead + 1);
        } else {
            $me = $this->agentSelfStats($user->id);
        }

        return response()->json([
            'top' => $top,
            'me' => $me,
            'total_ranked' => $total,
            'min_reviews' => $minReviews,
        ]);
    }

    /**
     * Per-agent aggregate (visible + client-authored only) gated at
     * $minReviews, as a fresh query builder so it can be wrapped in fromSub()
     * by agentLeaderboard(). Mirrors aggregateAgents()'s join/policy exactly.
     */
    private function qualifyingAgentsSub(int $minReviews)
    {
        return DB::table('agent_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.agent_user_id')
            ->join('users as cu', 'cu.id', '=', 'r.client_user_id')
            ->leftJoin('roles as cro', 'cro.id', '=', 'cu.role_id')
            ->leftJoin('agents as a', 'a.user_id', '=', 'r.agent_user_id')
            ->leftJoin('team_agents as ta', function ($j) {
                $j->on('ta.agent_id', '=', 'a.id')
                  ->where('ta.status', '=', 'active');
            })
            ->leftJoin('teams as t', 't.id', '=', 'ta.team_id')
            ->where('r.status', 'visible')
            ->where('cro.name', 'client')
            ->groupBy('r.agent_user_id', 'u.name', 'u.avatar', 't.name')
            ->havingRaw('COUNT(*) >= ?', [$minReviews])
            ->select(
                'r.agent_user_id',
                'u.name',
                'u.avatar',
                't.name as team_name',
                DB::raw('COUNT(*) as total_reviews'),
                DB::raw('ROUND(AVG(r.overall_rating), 2) as avg_rating'),
            );
    }

    /** Shape a leaderboard row (public-safe fields only) with its rank. */
    private function leaderRow(object $r, int $rank): array
    {
        return [
            'agent_user_id' => (int) $r->agent_user_id,
            'name' => $r->name,
            'avatar' => $r->avatar,
            'team_name' => $r->team_name,
            'total_reviews' => (int) $r->total_reviews,
            'avg_rating' => (float) $r->avg_rating,
            'rank' => $rank,
        ];
    }

    /**
     * The caller's raw stats when they haven't met the review gate — same
     * visible + client-authored policy, but ungated, with rank = null so the
     * UI can show "N more reviews to qualify".
     */
    private function agentSelfStats(int $userId): array
    {
        $row = DB::table('agent_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.agent_user_id')
            ->join('users as cu', 'cu.id', '=', 'r.client_user_id')
            ->leftJoin('roles as cro', 'cro.id', '=', 'cu.role_id')
            ->where('r.agent_user_id', $userId)
            ->where('r.status', 'visible')
            ->where('cro.name', 'client')
            ->groupBy('r.agent_user_id', 'u.name', 'u.avatar')
            ->select(
                'u.name',
                'u.avatar',
                DB::raw('COUNT(*) as total_reviews'),
                DB::raw('ROUND(AVG(r.overall_rating), 2) as avg_rating'),
            )
            ->first();

        return [
            'agent_user_id' => $userId,
            'name' => $row->name ?? Auth::user()->name,
            'avatar' => $row->avatar ?? null,
            'team_name' => null,
            'total_reviews' => (int) ($row->total_reviews ?? 0),
            'avg_rating' => $row && $row->avg_rating !== null ? (float) $row->avg_rating : null,
            'rank' => null,
        ];
    }

    /**
     * Shared agent-rollup query for the leaderboard endpoints. Returns
     * one row per agent matching the predicate, ordered by either avg
     * rating or total count. Joins users + agents + team_agents so the
     * frontend has display name + team without a second roundtrip.
     *
     * @param  \Closure  $applyFilter  receives the base query (agent_reviews
     *                                  as `r`) so callers can scope by tag, etc.
     * @param  string  $orderBy        'avg_rating_desc' | 'total_desc'
     * @param  int  $limit
     * @param  int  $minReviews        gates the result set
     */
    private function aggregateAgents(
        \Closure $applyFilter,
        string $orderBy,
        int $limit,
        int $minReviews,
    ): array {
        $q = DB::table('agent_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.agent_user_id')
            // Client side — needed so we can filter on role.name='client'.
            // Agent-to-agent reviews still appear in the agent profile
            // list with the "Inquired as agent" badge but never count
            // toward the leaderboards (matches the public-aggregate
            // policy in AgentRatingRollupService + summary()).
            ->join('users as cu', 'cu.id', '=', 'r.client_user_id')
            ->leftJoin('roles as cro', 'cro.id', '=', 'cu.role_id')
            ->leftJoin('agents as a', 'a.user_id', '=', 'r.agent_user_id')
            ->leftJoin('team_agents as ta', function ($j) {
                $j->on('ta.agent_id', '=', 'a.id')
                  ->where('ta.status', '=', 'active');
            })
            ->leftJoin('teams as t', 't.id', '=', 'ta.team_id')
            ->where('r.status', 'visible')
            ->where('cro.name', 'client');

        $applyFilter($q);

        $q->groupBy('r.agent_user_id', 'u.name', 'u.avatar', 't.name')
          ->havingRaw('COUNT(*) >= ?', [$minReviews])
          ->limit($limit)
          ->select(
              'r.agent_user_id',
              'u.name',
              'u.avatar',
              't.name as team_name',
              DB::raw('COUNT(*) as total_reviews'),
              DB::raw('ROUND(AVG(r.overall_rating), 2) as avg_rating'),
          );

        match ($orderBy) {
            'avg_rating_desc' => $q->orderByRaw('AVG(r.overall_rating) DESC, COUNT(*) DESC'),
            'total_desc'      => $q->orderByRaw('COUNT(*) DESC, AVG(r.overall_rating) DESC'),
        };

        return $q->get()->map(fn ($r) => [
            'agent_user_id' => (int) $r->agent_user_id,
            'name' => $r->name,
            'avatar' => $r->avatar,
            'team_name' => $r->team_name,
            'total_reviews' => (int) $r->total_reviews,
            'avg_rating' => (float) $r->avg_rating,
        ])->all();
    }
}
