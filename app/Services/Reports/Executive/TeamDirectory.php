<?php

namespace App\Services\Reports\Executive;

use Illuminate\Support\Facades\DB;

/**
 * Which team each agent is credited to in the Executive Report.
 *
 * `team_agents` has no unique key on agent_id, so an agent can sit in two
 * teams at once. Joining it into an aggregate would count that agent's
 * listings twice — once per team — and the teams' totals would no longer add
 * up to the platform's. Instead every agent is credited to exactly ONE team,
 * the lowest active team id (deterministic, so a number never jumps between
 * teams from one request to the next), and team figures are folded from
 * agent-level counts in PHP.
 *
 * Membership is CURRENT: it is synced from Leuterio Realty at login and
 * updated in place, so past activity is credited to the agent's team today.
 * The report says so in its notes.
 *
 * Only active memberships of active teams count, matching the Business
 * Pulse's most-active ranking (OwnerPulseService::mostActive()).
 */
final class TeamDirectory
{
    /** @var array{teamOf: array<int,int>, names: array<int,string>, members: array<int,int[]>, leaders: array<int,int[]>}|null */
    private ?array $data = null;

    /** The team an agent is credited to, or null for no team. */
    public function teamOf(int $agentId): ?int
    {
        return $this->data()['teamOf'][$agentId] ?? null;
    }

    /** @return array<int, int> agent id => team id, for every agent with a team */
    public function teamOfAll(): array
    {
        return $this->data()['teamOf'];
    }

    /** Agent ids credited to a team. */
    public function members(int $teamId): array
    {
        return $this->data()['members'][$teamId] ?? [];
    }

    /** Every agent id credited to some team (the complement is "No team"). */
    public function allMemberIds(): array
    {
        return array_keys($this->data()['teamOf']);
    }

    /** Agent ids flagged leader of a team (among its credited members). */
    public function leaders(int $teamId): array
    {
        return $this->data()['leaders'][$teamId] ?? [];
    }

    public function name(?int $teamId): ?string
    {
        return $teamId === null ? null : ($this->data()['names'][$teamId] ?? null);
    }

    /** @return array<int, string> active team id => name, for teams with credited members */
    public function teams(): array
    {
        return $this->data()['names'];
    }

    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $rows = DB::table('team_agents as ta')
            ->join('teams as t', 't.id', '=', 'ta.team_id')
            ->where('ta.status', 'active')
            ->where('t.status', 'active')
            ->orderBy('ta.team_id')
            ->get(['ta.agent_id', 'ta.team_id', 'ta.is_leader', 't.name']);

        $teamOf = [];
        $leaderRows = [];
        foreach ($rows as $row) {
            $agent = (int) $row->agent_id;
            // Ordered by team id, so the first membership seen is the lowest.
            $teamOf[$agent] ??= (int) $row->team_id;
            if ($row->is_leader) {
                $leaderRows[] = [$agent, (int) $row->team_id];
            }
        }

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row->team_id] ??= (string) $row->name;
        }

        $members = [];
        foreach ($teamOf as $agent => $team) {
            $members[$team][] = $agent;
        }
        // A team whose every member is credited elsewhere has no members here
        // and drops out — its people are counted under their other team.
        $names = array_intersect_key($names, $members);

        $leaders = [];
        foreach ($leaderRows as [$agent, $team]) {
            if (($teamOf[$agent] ?? null) === $team) {
                $leaders[$team][] = $agent;
            }
        }

        return $this->data = compact('teamOf', 'names', 'members', 'leaders');
    }
}
