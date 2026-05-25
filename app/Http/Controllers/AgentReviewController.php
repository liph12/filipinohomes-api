<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgentReviewResource;
use App\Models\AgentReview;
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
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $query = AgentReview::query()
            ->where('agent_user_id', $agentUserId)
            ->where('status', 'visible')
            ->with(['client:id,name,avatar', 'response'])
            ->latest();

        if (!empty($validated['rating'])) {
            $query->where('overall_rating', (int) $validated['rating']);
        }
        if (!empty($validated['tag'])) {
            $tag = $validated['tag'];
            // JSON_CONTAINS for MySQL; the column stores a JSON array.
            $query->whereJsonContains('tags', $tag);
        }

        return AgentReviewResource::collection(
            $query->paginate($validated['per_page'] ?? 10)
        );
    }

    /**
     * Aggregate stats for an agent's review section — used by the
     * frontend AgentReviewsSection header. Counts grouped by star.
     */
    public function summary(int $agentUserId)
    {
        $rows = AgentReview::query()
            ->selectRaw('overall_rating, COUNT(*) as n')
            ->where('agent_user_id', $agentUserId)
            ->where('status', 'visible')
            ->groupBy('overall_rating')
            ->pluck('n', 'overall_rating');

        $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
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

        return response()->json([
            'avg_rating' => $avg,
            'total_reviews' => $total,
            'breakdown' => $breakdown,
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
        $eligibility = app(ReviewEligibilityService::class)->check($clientId, $conv);
        if (!$eligibility['eligible']) {
            abort(422, $eligibility['reason'] ?? 'You are not eligible to rate this agent yet.');
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

        // Seal the pivot so the inline chat card doesn't re-render.
        DB::table('conversation_users')
            ->where('conversation_id', $conv->id)
            ->where('user_id', $clientId)
            ->update(['rate_prompt_shown_at' => now()]);

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
            ->with(['client:id,name,avatar', 'agent:id,name,avatar', 'response', 'hiddenByUser:id,name'])
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
}
