<?php

namespace App\Services\LeuterioreRealty;

use App\Models\Team;
use App\Models\TeamAgent;
use App\Models\User;

class TeamSyncService
{
    public function syncForUser(User $user): void
    {
        $agent = $user->agent;
        if (!$agent) {
            return;
        }

        // Already in team_agents — skip
        if (TeamAgent::where('agent_id', $agent->id)->exists()) {
            return;
        }

        // Fetch from LR API
        $lrData = (new LrApiService())->fetchAgentByEmail($user->email);
        if (!$lrData || !isset($lrData['team']['sales_team']['teamname'])) {
            return;
        }

        $teamName = $lrData['team']['sales_team']['teamname'];
        $team = Team::where('name', $teamName)->first();
        if (!$team) {
            return;
        }

        // Add agent to team
        TeamAgent::create([
            'name'     => $user->name,
            'team_id'  => $team->id,
            'agent_id' => $agent->id,
            'status'   => 'active',
        ]);

        // If team leader, update team's leader_id
        if (($lrData['team']['isleader'] ?? 0) == 1) {
            $team->update(['leader_id' => $agent->id]);
        }
    }
}
