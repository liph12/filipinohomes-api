<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\TeamAgent;

class TeamLeadershipService
{
    private array $leaderForAgentCache = [];
    private array $ledMembersCache = [];
    private array $isLeaderCache = [];
    private array $ledAgentIdsCache = [];
    private array $ledTeamIdsCache = [];

    public function findTeamLeaderUserIdFor(int $agentUserId): ?int
    {
        if (array_key_exists($agentUserId, $this->leaderForAgentCache)) {
            return $this->leaderForAgentCache[$agentUserId];
        }

        $agentId = Agent::where('user_id', $agentUserId)->value('id');
        if (!$agentId) {
            return $this->leaderForAgentCache[$agentUserId] = null;
        }

        $teamId = TeamAgent::where('agent_id', $agentId)
            ->where('status', 'active')
            ->value('team_id');
        if (!$teamId) {
            return $this->leaderForAgentCache[$agentUserId] = null;
        }

        $leaderAgentId = TeamAgent::where('team_id', $teamId)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->value('agent_id');
        if (!$leaderAgentId || $leaderAgentId === $agentId) {
            return $this->leaderForAgentCache[$agentUserId] = null;
        }

        return $this->leaderForAgentCache[$agentUserId] = Agent::where('id', $leaderAgentId)->value('user_id');
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
