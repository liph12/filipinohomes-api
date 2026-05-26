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
                'activeConversation:id,chat_id,status,agent_user_id',
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

        $rows = DB::table('agent_reviews as r')
            ->join('agents as a', 'a.user_id', '=', 'r.agent_user_id')
            ->join('team_agents as ta', function ($j) {
                $j->on('ta.agent_id', '=', 'a.id')
                  ->where('ta.status', '=', 'active');
            })
            ->join('teams as t', 't.id', '=', 'ta.team_id')
            ->where('r.status', 'visible')
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
            ->leftJoin('agents as a', 'a.user_id', '=', 'r.agent_user_id')
            ->leftJoin('team_agents as ta', function ($j) {
                $j->on('ta.agent_id', '=', 'a.id')
                  ->where('ta.status', '=', 'active');
            })
            ->leftJoin('teams as t', 't.id', '=', 'ta.team_id')
            ->where('r.status', 'visible');

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
