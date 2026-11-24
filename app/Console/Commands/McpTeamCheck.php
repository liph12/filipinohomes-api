<?php

namespace App\Console\Commands;

use App\Mcp\Support\McpRole;
use App\Models\Agent;
use App\Services\Reports\Executive\TeamDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Counts, and only counts, of the team data the MCP server reads: how many
 * teams have a leader, how many leaders would be shown the team tools, and the
 * data oddities that make a team's numbers differ from what the portal shows.
 * Nothing is changed and nobody's name, email or number is printed. Meant to be
 * run once on production before team leaders are given keys (docs/mcp.md).
 */
class McpTeamCheck extends Command
{
    protected $signature = 'fhmcp:team-check';

    protected $description = 'Count the teams, leaders and members the MCP server reads (counts only; nothing is changed)';

    public function handle(): int
    {
        $teams = DB::table('teams')->get(['id', 'status']);
        $activeTeams = $teams->where('status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $memberships = DB::table('team_agents')
            ->where('status', 'active')
            ->whereIn('team_id', $activeTeams ?: [0])
            ->get(['team_id', 'agent_id', 'is_leader']);

        $perTeam = $memberships->groupBy('team_id')->map->count()->sort()->values();
        $leaderRows = $memberships->where('is_leader', 1);
        $teamsWithLeader = $leaderRows->pluck('team_id')->unique();
        $leaderAgents = $leaderRows->pluck('agent_id')->unique()->map(fn ($id): int => (int) $id)->values();

        // What the leaders would be: judged exactly as a key is, one by one.
        $kinds = ['team_leader' => 0, 'agent' => 0, 'no access' => 0];

        Agent::query()->with('user.role', 'user.agent')->whereIn('id', $leaderAgents->all() ?: [0])->get()->each(function (Agent $agent) use (&$kinds): void {
            $role = $agent->user === null ? null : McpRole::of($agent->user);
            $kinds[$role?->value ?? 'no access']++;
        });

        $directory = new TeamDirectory;

        $rows = [
            ['Teams', $teams->count()],
            ['  active', count($activeTeams)],
            ['  inactive or resigned', $teams->count() - count($activeTeams)],
            ['Active teams with an active member', $perTeam->count()],
            ['Active teams with a leader', $teamsWithLeader->count()],
            ['Active teams with members but no leader', $perTeam->count() - $teamsWithLeader->count()],
            ['Active teams the reports can show (some member is counted in them)', count($directory->teams())],
            ['Members per active team: smallest / middle / largest', $perTeam->isEmpty() ? '-' : $perTeam->first().' / '.$perTeam[intdiv($perTeam->count(), 2)].' / '.$perTeam->last()],
            ['Leaders (agents with a leader place in an active team)', $leaderAgents->count()],
            ['  who would be shown the team tools', $kinds['team_leader']],
            ['  who would stay a plain agent (their team has no counted members)', $kinds['agent']],
            ['  who could not use the server at all (not an active agent)', $kinds['no access']],
            ['Leaders of two or more teams', $leaderRows->groupBy('agent_id')->filter(fn ($rows): bool => $rows->pluck('team_id')->unique()->count() > 1)->count()],
            ['Teams with two or more leaders', $leaderRows->groupBy('team_id')->filter(fn ($rows): bool => $rows->pluck('agent_id')->unique()->count() > 1)->count()],
            ['Agents in two or more active teams (counted in the lowest-numbered only)', $memberships->groupBy('agent_id')->filter(fn ($rows): bool => $rows->pluck('team_id')->unique()->count() > 1)->count()],
        ];

        $this->table(['Check', 'Count'], $rows);
        $this->line('Counts only: no names, emails or numbers are shown, and nothing was changed.');

        return self::SUCCESS;
    }
}
