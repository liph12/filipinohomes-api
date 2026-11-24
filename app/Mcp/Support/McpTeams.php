<?php

namespace App\Mcp\Support;

use App\Models\TeamAgent;
use App\Models\User;
use App\Services\Reports\Executive\TeamDirectory;
use WeakMap;

/**
 * Who leads which team, as far as the MCP server is concerned.
 *
 * There is no team-leader role in FH: a leader is an agent whose membership of
 * a team (team_agents) is flagged is_leader and is active. So this is worked
 * out from the data each time a key is used, like every other role here, and
 * a leader who stops leading is an ordinary agent on the next request.
 *
 * A team counts only if it is active AND the reports know it, because the
 * reports (TeamDirectory) credit every agent to exactly one team, the lowest
 * active team id. A team none of whose members is credited to it has no
 * numbers to show, and a leader of only such teams stays a plain agent rather
 * than being handed a view that is silently empty.
 *
 * Only reads, through the models; the answer is remembered for the person
 * object it was asked about (one request), never across requests.
 */
final class McpTeams
{
    /** @var WeakMap<User, list<int>>|null */
    private static ?WeakMap $led = null;

    /**
     * The teams this person leads and the reports can see, lowest id first.
     * Empty for anyone who is not a leader.
     *
     * @return list<int>
     */
    public static function ledBy(User $user): array
    {
        self::$led ??= new WeakMap;

        return self::$led[$user] ??= self::resolve($user);
    }

    /** Whether the person leads at least one such team. */
    public static function leads(User $user): bool
    {
        return self::ledBy($user) !== [];
    }

    /**
     * The agents credited to a team in the reports: the people whose numbers
     * make up the team's.
     *
     * @return list<int>
     */
    public static function members(int $teamId): array
    {
        return array_values(array_map('intval', self::directory()->members($teamId)));
    }

    /**
     * Each team this person leads with the people in it, for the roster tool.
     * Names and ids only: no contact details.
     *
     * @return list<array{team_id: int, team: string, members: list<array{agent_id: int, name: string, is_leader: bool, agent_status: string|null, counted_in_team_numbers: bool}>}>
     */
    public static function roster(User $user, ?int $onlyTeam = null): array
    {
        $directory = self::directory();
        $teams = [];

        foreach (self::ledBy($user) as $teamId) {
            if ($onlyTeam !== null && $teamId !== $onlyTeam) {
                continue;
            }

            $rows = TeamAgent::query()
                ->with(['agent:id,user_id,first_name,last_name,status', 'agent.user:id,name'])
                ->where('team_id', $teamId)
                ->where('status', 'active')
                ->get();

            $members = [];

            foreach ($rows as $row) {
                $agent = $row->agent;

                if ($agent === null) {
                    continue;
                }

                $members[] = [
                    'agent_id' => (int) $agent->id,
                    'name' => trim($agent->first_name.' '.$agent->last_name) ?: (string) ($agent->user?->name ?? 'Agent '.$agent->id),
                    'is_leader' => (bool) $row->is_leader,
                    'agent_status' => $agent->status,
                    // The reports credit an agent who sits in two teams to the lower-numbered one only.
                    'counted_in_team_numbers' => $directory->teamOf((int) $agent->id) === $teamId,
                ];
            }

            usort($members, fn (array $a, array $b): int => [! $a['is_leader'], $a['name']] <=> [! $b['is_leader'], $b['name']]);

            $teams[] = ['team_id' => $teamId, 'team' => (string) ($directory->name($teamId) ?? 'Team '.$teamId), 'members' => $members];
        }

        return $teams;
    }

    /** @return list<int> */
    private static function resolve(User $user): array
    {
        $agentId = $user->agent?->id;

        if ($agentId === null) {
            return [];
        }

        $led = TeamAgent::query()
            ->where('agent_id', $agentId)
            ->where('is_leader', true)
            ->where('status', 'active')
            ->whereHas('team', fn ($team) => $team->where('status', 'active'))
            ->pluck('team_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($led === []) {
            return [];
        }

        return array_values(array_intersect($led, array_keys(self::directory()->teams())));
    }

    private static function directory(): TeamDirectory
    {
        return app(TeamDirectory::class);
    }
}
