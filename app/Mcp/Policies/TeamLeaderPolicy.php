<?php

namespace App\Mcp\Policies;

use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpRole;
use App\Mcp\Support\McpTeams;
use App\Services\Reports\Executive\MetricRegistry;
use InvalidArgumentException;
use LogicException;

/**
 * A team leader reads their team: its totals, each member's numbers and the
 * records behind them, the same things their Team Activity page shows. Not
 * another team, and not the company.
 *
 *  - Every ask is held to a team they lead (constrain): left out, it is their
 *    team (or they are asked which, if they lead several); named, it has to be
 *    one of theirs, and 0 ("no team") never is. A member can be named with
 *    agent_id, but only one of the team's own.
 *  - Only numbers that take a team filter are open to them, and not the teams
 *    numbers: those accept a team filter and still answer for the whole
 *    company (a test checks that every number open here really narrows).
 *  - They can split a team by member, project, place, category and type, and
 *    look up places, projects and types, not teams.
 *
 * Their own numbers, as an agent, are the agent tools' (AgentPolicy), which a
 * team leader keeps.
 */
final class TeamLeaderPolicy implements McpPolicy
{
    public function role(): McpRole
    {
        return McpRole::TeamLeader;
    }

    public function allowsMetric(string $metric): bool
    {
        return MetricRegistry::has($metric)
            && in_array('team_id', MetricRegistry::filters($metric), true)
            && ! str_starts_with($metric, 'teams.');
    }

    public function filters(): array
    {
        return ['team_id', 'agent_id', 'psa_region', 'island', 'province_id', 'city_id', 'project_id', 'type_id', 'category', 'status'];
    }

    public function forced(McpActor $actor): array
    {
        return [];
    }

    public function constrain(array $filters, McpActor $actor): array
    {
        $teams = $actor->teamIds;

        if ($teams === []) {
            // McpRole::of() gives the role only to someone who leads a team; this is the second lock.
            throw new LogicException('A team leader without a team has no scope to ask in.');
        }

        $named = isset($filters['team_id']) ? (int) $filters['team_id'] : null;

        if ($named === null) {
            if (count($teams) > 1) {
                throw new InvalidArgumentException('You lead more than one team: say which with team_id ('.implode(', ', $teams).'). The team_roster tool lists them with their members.');
            }

            $filters['team_id'] = $teams[0];
        } elseif (! in_array($named, $teams, true)) {
            throw new InvalidArgumentException('team_id has to be a team you lead ('.implode(', ', $teams).'). Other teams, and 0, are not available to your account.');
        }

        if (isset($filters['agent_id']) && ! in_array((int) $filters['agent_id'], McpTeams::members((int) $filters['team_id']), true)) {
            throw new InvalidArgumentException('agent_id is not a member of that team. The team_roster tool lists the members and their ids.');
        }

        return $filters;
    }

    public function dimensions(): array
    {
        return ['agent', 'project', 'city', 'province', 'category', 'type'];
    }

    public function lookups(): array
    {
        return ['projects', 'provinces', 'cities', 'types'];
    }
}
