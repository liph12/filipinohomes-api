<?php

namespace App\Mcp\Policies;

use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpRole;
use App\Services\Reports\Executive\MetricRegistry;
use LogicException;

/**
 * An agent reads their own numbers and nothing else.
 *
 *  - Every ask is forced to their agent id (`agent_id`), after whatever they
 *    sent, so no argument can widen it. The filters that would (agent, team,
 *    office) are not even in their tools' schemas.
 *  - Only metrics that can be cut to one agent are open to them. The company's
 *    own workings (how many agents, who is dormant, the users and teams
 *    counts) are an admin's.
 *  - They can split their numbers by place, project, type and category, not by
 *    agent or team, and can look up places, projects and types, not teams.
 */
final class AgentPolicy implements McpPolicy
{
    /** Metrics that take an agent filter but are the company's workings, not an agent's own numbers. */
    private const COMPANY_ONLY = ['leads.to_idle_agents'];

    public function role(): McpRole
    {
        return McpRole::Agent;
    }

    public function allowsMetric(string $metric): bool
    {
        return MetricRegistry::has($metric)
            && in_array('agent_id', MetricRegistry::filters($metric), true)
            && ! str_starts_with($metric, 'agents.')
            && ! in_array($metric, self::COMPANY_ONLY, true);
    }

    public function filters(): array
    {
        return ['psa_region', 'island', 'province_id', 'city_id', 'project_id', 'type_id', 'category', 'status'];
    }

    public function forced(McpActor $actor): array
    {
        if ($actor->agentId === null) {
            // McpRole::of() only gives an agent the role with an agent row; this is the second lock.
            throw new LogicException('An agent without an agent row has no scope to ask in.');
        }

        return ['agent_id' => $actor->agentId];
    }

    public function dimensions(): array
    {
        return ['project', 'city', 'province', 'category', 'type'];
    }

    public function lookups(): array
    {
        return ['projects', 'provinces', 'cities', 'types'];
    }
}
