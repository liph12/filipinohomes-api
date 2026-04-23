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

        $isLeader = ($lrData['team']['isleader'] ?? 0) == 1;
        $existing = TeamAgent::where('agent_id', $agent->id)->first();

        if ($existing) {
            // Same team and same leader status — skip
            if ($existing->team_id === $team->id && $existing->is_leader === $isLeader) {
                return;
            }

            // Team or leader status changed — update record
            $existing->update([
                'team_id'   => $team->id,
                'is_leader' => $isLeader,
            ]);
        } else {
            // New entry
            TeamAgent::create([
                'team_id'   => $team->id,
                'agent_id'  => $agent->id,
                'is_leader' => $isLeader,
                'status'    => 'active',
            ]);
        }
    }
}
