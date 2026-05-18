<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\TeamAgent;

class TeamLeadershipService
{
    private array $leaderForAgentCache = [];
    private array $ledMembersCache = [];
    private array $isLeaderCache = [];

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
