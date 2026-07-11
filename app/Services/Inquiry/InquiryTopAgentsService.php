<?php

namespace App\Services\Inquiry;

use Illuminate\Support\Facades\DB;

/**
 * Inquiry Analytics — agents ranked by how many inquiries their listings drew,
 * for the current filter scope. Per agent: inquiry count, how many they replied
 * to (+ reply rate), closed conversations, unread incoming messages, last
 * active time, and their team.
 *
 * Inquiry = a `chats` row (type='listing'); the agent is the OWNER of the
 * inquired listing (listings.agent_id). Reuses the shared baseInquiryQuery()
 * so every date/category/type/location filter applies identically to the
 * clients table. The heavy per-conversation metrics (replied/closed/unread)
 * are computed only for the page's agents (whereIn) so the message joins stay
 * bounded.
 */
class InquiryTopAgentsService extends InquiryInsightsService
{
    public function agents(int $page = 1, int $perPage = 25, string $sortBy = 'count', string $sortDir = 'desc'): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        // Base scope + join to the listing's agent and the agent's user row
        // (name / avatar / last-active). agent_id is non-nullable so an inner
        // join can't drop a real inquiry.
        $scoped = fn () => $this->baseInquiryQuery()
            ->join('agents', 'agents.id', '=', 'listings.agent_id')
            ->join('users as ag', 'ag.id', '=', 'agents.user_id');

        $totalAgents = (int) DB::query()
            ->fromSub($scoped()->select('agents.id')->groupBy('agents.id'), 't')
            ->count();
        $totalInquiries = (int) $scoped()->count();

        $orderCol = $sortBy === 'name' ? 'name' : 'inquiry_count';
        $dir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $rows = $scoped()
            ->groupBy('agents.id', 'agents.user_id', 'ag.name', 'ag.avatar', 'ag.last_online_at', 'agents.within_1h_response_pct')
            ->orderBy($orderCol, $dir)
            ->orderByDesc(DB::raw('COUNT(DISTINCT chats.id)'))
            ->orderBy('agents.id', 'asc') // deterministic pagination
            ->forPage($page, $perPage)
            ->get([
                DB::raw('agents.id as agent_id'),
                DB::raw('agents.user_id as user_id'),
                DB::raw('ag.name as name'),
                DB::raw('ag.avatar as avatar'),
                DB::raw('ag.last_online_at as last_online_at'),
                DB::raw('agents.within_1h_response_pct as within_1h_pct'),
                DB::raw('COUNT(DISTINCT chats.id) as inquiry_count'),
            ]);

        $agentIds = $rows->pluck('agent_id')->map(fn ($v) => (int) $v)->all();
        $replied  = $this->repliedByAgent($agentIds);
        $closed   = $this->closedByAgent($agentIds);
        $unread   = $this->unreadByAgent($agentIds);
        $teams    = $this->teamByAgent($agentIds);

        $offset = ($page - 1) * $perPage;
        $data = $rows->values()->map(function ($r, $i) use ($replied, $closed, $unread, $teams, $offset) {
            $aid = (int) $r->agent_id;
            $inq = (int) $r->inquiry_count;
            $rep = $replied[$aid] ?? 0;

            return [
                'rank'           => $offset + $i + 1,
                'agent_id'       => $aid,
                'user_id'        => (int) $r->user_id,
                'name'           => (string) ($r->name ?? 'Unknown'),
                'photo'          => $r->avatar ?: null,
                'last_active'    => $r->last_online_at ?: null,
                'team'           => $teams[$aid] ?? null,
                'inquiry_count'  => $inq,
                'replied'        => $rep,
                // Share of this agent's in-scope inquiries they've responded to.
                'reply_rate'     => $inq > 0 ? round($rep / $inq * 100) : 0,
                // All-time "responds within 1 hour" %, precomputed hourly by
                // agents:recompute-response-metrics (null until first run).
                'within_1h_pct'  => $r->within_1h_pct !== null ? (int) $r->within_1h_pct : null,
                'closed'         => $closed[$aid] ?? 0,
                'unread'         => $unread[$aid] ?? 0,
            ];
        })->all();

        return [
            'data'   => $data,
            'totals' => [
                'total_agents'             => $totalAgents,
                'total_inquiries_in_scope' => $totalInquiries,
            ],
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($totalAgents / $perPage)),
                'sort_by'   => $sortBy,
                'sort_dir'  => $dir,
                'date_from' => $this->dateFrom,
                'date_to'   => $this->dateTo,
            ],
        ];
    }

    /** Inquiries (chats) the agent has posted at least one message in. */
    private function repliedByAgent(array $agentIds): array
    {
        if (empty($agentIds)) {
            return [];
        }
        return $this->baseInquiryQuery()
            ->join('agents', 'agents.id', '=', 'listings.agent_id')
            ->whereIn('agents.id', $agentIds)
            ->join('conversations', function ($j) {
                $j->on('conversations.chat_id', '=', 'chats.id')
                    ->whereNull('conversations.deleted_at');
            })
            ->join('messages', function ($j) {
                $j->on('messages.conversation_id', '=', 'conversations.id')
                    // The agent's own message = a reply.
                    ->on('messages.user_id', '=', 'agents.user_id')
                    ->where('messages.status', '!=', 'deleted');
            })
            ->groupBy('agents.id')
            ->select('agents.id', DB::raw('COUNT(DISTINCT chats.id) as n'))
            ->pluck('n', 'agents.id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Closed inquiry conversations per agent. */
    private function closedByAgent(array $agentIds): array
    {
        if (empty($agentIds)) {
            return [];
        }
        return $this->baseInquiryQuery()
            ->join('agents', 'agents.id', '=', 'listings.agent_id')
            ->whereIn('agents.id', $agentIds)
            ->join('conversations', function ($j) {
                $j->on('conversations.chat_id', '=', 'chats.id')
                    ->whereNull('conversations.deleted_at')
                    ->where('conversations.status', '=', 'closed');
            })
            ->groupBy('agents.id')
            ->select('agents.id', DB::raw('COUNT(DISTINCT conversations.id) as n'))
            ->pluck('n', 'agents.id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Incoming messages the agent hasn't read: sent by the OTHER party (the
     * client, not the agent) and newer than the agent's last_read_at cursor in
     * conversation_users (or the agent never opened the thread).
     */
    private function unreadByAgent(array $agentIds): array
    {
        if (empty($agentIds)) {
            return [];
        }
        return $this->baseInquiryQuery()
            ->join('agents', 'agents.id', '=', 'listings.agent_id')
            ->whereIn('agents.id', $agentIds)
            ->join('conversations', function ($j) {
                $j->on('conversations.chat_id', '=', 'chats.id')
                    ->whereNull('conversations.deleted_at');
            })
            ->join('messages', function ($j) {
                $j->on('messages.conversation_id', '=', 'conversations.id')
                    ->whereColumn('messages.user_id', '!=', 'agents.user_id')
                    ->where('messages.status', '!=', 'deleted');
            })
            ->leftJoin('conversation_users as cu', function ($j) {
                $j->on('cu.conversation_id', '=', 'conversations.id')
                    ->on('cu.user_id', '=', 'agents.user_id');
            })
            ->where(function ($w) {
                $w->whereNull('cu.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'cu.last_read_at');
            })
            ->groupBy('agents.id')
            ->select('agents.id', DB::raw('COUNT(DISTINCT messages.id) as n'))
            ->pluck('n', 'agents.id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** agent_id => active team name. */
    private function teamByAgent(array $agentIds): array
    {
        if (empty($agentIds)) {
            return [];
        }
        return DB::table('team_agents')
            ->join('teams', 'teams.id', '=', 'team_agents.team_id')
            ->whereIn('team_agents.agent_id', $agentIds)
            ->where('team_agents.status', 'active')
            ->pluck('teams.name', 'team_agents.agent_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
