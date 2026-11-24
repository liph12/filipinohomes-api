<?php

namespace App\Mcp\Tools\TeamLeader;

use App\Mcp\Policies\McpPolicy;
use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Tools\Kinds\MetricBreakdownTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('team_metric_breakdown')]
#[Title('My team\'s number, split')]
#[Description('One of YOUR TEAM\'s numbers split by member (agent), project, city, province, category or type, biggest first. Splitting by agent shows how each member contributes. Read-only.')]
final class TeamMetricBreakdown extends MetricBreakdownTool
{
    protected function policy(): McpPolicy
    {
        return new TeamLeaderPolicy;
    }
}
