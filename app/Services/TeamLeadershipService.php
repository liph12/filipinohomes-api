<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Team;
use App\Models\TeamAgent;

class TeamLeadershipService
{
    // Memoizes the full team context (team_id + team_name + leader_user_id)
    // per agent user_id. Both findTeamLeaderUserIdFor() and
    // findTeamInfoForAgent() delegate here so the team_agents pivot is hit
    // exactly once per request even when multiple callers ask about the
    // same agent (e.g. ChatController + MessageNotificationMailer in a
    // single submission).
    private array $teamContextCache = [];
    private array $ledMembersCache = [];
    private array $isLeaderCache = [];
    private array $ledAgentIdsCache = [];
    private array $ledTeamIdsCache = [];

    public function findTeamLeaderUserIdFor(int $agentUserId): ?int
    {
        return $this->resolveAgentTeamContext($agentUserId)['leader_user_id'] ?? null;
    }

    /**
     * Full team context for an agent: team id + name, plus the team leader's
     * user_id (null when the team has no separate leader OR the agent IS the
     * leader — same semantics as findTeamLeaderUserIdFor). Returns null when
     * the user isn't an agent or isn't on any active team.
     *
     * Used by the listing-inquiry email fan-out so admins/team-leaders can
     * see which team is involved, even when no leader exists.
     *
     * @return array{team_id:int, team_name:string, leader_user_id:int|null}|null
     */
    public function findTeamInfoForAgent(int $agentUserId): ?array
    {
        return $this->resolveAgentTeamContext($agentUserId);
    }

    /**
     * Single source of truth for "which team is this agent on, and who
     * leads it". Cached per-request so repeated lookups for the same agent
     * don't re-hit the database.
     *
     * @return array{team_id:int, team_name:string, leader_user_id:int|null}|null
     */
    private function resolveAgentTeamContext(int $agentUserId): ?array
    {
        if (array_key_exists($agentUserId, $this->teamContextCache)) {
            return $this->teamContextCache[$agentUserId];
        }

        $agentId = Agent::where('user_id', $agentUserId)->value('id');
        if (!$agentId) {
            return $this->teamContextCache[$agentUserId] = null;
        }

        $teamId = TeamAgent::where('agent_id', $agentId)
            ->where('status', 'active')
            ->value('team_id');
        if (!$teamId) {
            return $this->teamContextCache[$agentUserId] = null;
        }

        $teamName = Team::where('id', $teamId)->value('name');
        if ($teamName === null) {
            return $this->teamContextCache[$agentUserId] = null;
        }

        $leaderAgentId = TeamAgent::where('team_id', $teamId)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->value('agent_id');

        // Same semantics as the legacy method: a team without a separate
        // leader, OR a team where the agent themselves IS the leader,
        // resolves to leader_user_id=null so callers can decide what to do
        // (the inquiry-email fan-out skips the team-leader send in both
        // cases — the agent doesn't need to BCC themselves about their
        // own listing).
        $leaderUserId = null;
        if ($leaderAgentId && (int) $leaderAgentId !== (int) $agentId) {
            $resolved = Agent::where('id', $leaderAgentId)->value('user_id');
            $leaderUserId = $resolved !== null ? (int) $resolved : null;
        }

        return $this->teamContextCache[$agentUserId] = [
            'team_id'        => (int) $teamId,
            'team_name'      => (string) $teamName,
            'leader_user_id' => $leaderUserId,
        ];
    }

    public function getLedTeamMemberUserIds(int $leaderUserId): array
    {
        if (array_key_exists($leaderUserId, $this->ledMembersCache)) {
            return $this->ledMembersCache[$leaderUserId];
        }

        $leaderAgentId = Agent::where('user_id', $leaderUserId)->value('id');
        if (!$leaderAgentId) {
            return $this->ledMembersCache[$leaderUserId] = [];
        }

        $teamId = TeamAgent::where('agent_id', $leaderAgentId)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->value('team_id');
        if (!$teamId) {
            return $this->ledMembersCache[$leaderUserId] = [];
        }

        $memberAgentIds = TeamAgent::where('team_id', $teamId)
            ->where('status', 'active')
            ->pluck('agent_id')
            ->all();
        if (empty($memberAgentIds)) {
            return $this->ledMembersCache[$leaderUserId] = [];
        }

        return $this->ledMembersCache[$leaderUserId] = Agent::whereIn('id', $memberAgentIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Agent IDs (NOT user IDs) of everyone in the teams this user leads —
     * including the leader's own agent_id. Returns an empty array when the
     * user isn't a team leader. Listings keyed by `agent_id` make this the
     * natural shape for scoping audit queries to a leader's team.
     */
    public function getLedAgentIds(int $leaderUserId): array
    {
        if (array_key_exists($leaderUserId, $this->ledAgentIdsCache)) {
            return $this->ledAgentIdsCache[$leaderUserId];
        }

        $leaderAgentId = Agent::where('user_id', $leaderUserId)->value('id');
        if (!$leaderAgentId) {
            return $this->ledAgentIdsCache[$leaderUserId] = [];
        }

        // Each team a user leads (usually one, but the data model allows
        // multiple). Pull the team_ids first, then all member agent_ids
        // for those teams.
        $teamIds = TeamAgent::where('agent_id', $leaderAgentId)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->pluck('team_id')
            ->all();
        if (empty($teamIds)) {
            return $this->ledAgentIdsCache[$leaderUserId] = [];
        }

        $agentIds = TeamAgent::whereIn('team_id', $teamIds)
            ->where('status', 'active')
            ->pluck('agent_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->ledAgentIdsCache[$leaderUserId] = $agentIds;
    }

    /**
     * Team IDs this user leads (where is_leader=true on the team_agents
     * pivot). Empty array when the user isn't a leader. Useful for letting
     * the frontend filter list endpoints (e.g. /agents) by team.
     */
    public function getLedTeamIds(int $leaderUserId): array
    {
        if (array_key_exists($leaderUserId, $this->ledTeamIdsCache)) {
            return $this->ledTeamIdsCache[$leaderUserId];
        }

        $leaderAgentId = Agent::where('user_id', $leaderUserId)->value('id');
        if (!$leaderAgentId) {
            return $this->ledTeamIdsCache[$leaderUserId] = [];
        }

        return $this->ledTeamIdsCache[$leaderUserId] = TeamAgent::where('agent_id', $leaderAgentId)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->pluck('team_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function isTeamLeader(int $userId): bool
    {
        if (array_key_exists($userId, $this->isLeaderCache)) {
            return $this->isLeaderCache[$userId];
        }

        $agentId = Agent::where('user_id', $userId)->value('id');
        if (!$agentId) {
            return $this->isLeaderCache[$userId] = false;
        }

        return $this->isLeaderCache[$userId] = TeamAgent::where('agent_id', $agentId)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->exists();
    }
}
