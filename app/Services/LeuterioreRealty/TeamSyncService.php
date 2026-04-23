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

        $existing = TeamAgent::where('agent_id', $agent->id)->first();

        if ($existing) {
            // Already on same team — skip
            if ($existing->team_id === $team->id) {
                return;
            }

            // Team changed — update record
            $existing->update([
                'team_id' => $team->id,
                'name'    => $user->name,
            ]);

            // Remove leader_id from old team if this agent was the leader
            Team::where('id', $existing->getOriginal('team_id'))
                ->where('leader_id', $agent->id)
                ->update(['leader_id' => null]);
        } else {
            // New entry
            TeamAgent::create([
                'name'     => $user->name,
                'team_id'  => $team->id,
                'agent_id' => $agent->id,
                'status'   => 'active',
            ]);
        }

        // If team leader, update team's leader_id
        if (($lrData['team']['isleader'] ?? 0) == 1) {
            $team->update(['leader_id' => $agent->id]);
        }
    }
}
