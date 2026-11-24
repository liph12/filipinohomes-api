<?php

namespace App\Mcp\Tools\TeamLeader;

use App\Mcp\Policies\McpPolicy;
use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Tools\Kinds\MetricTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('team_metric')]
#[Title('My team\'s number')]
#[Description('One number about YOUR TEAM\'s listings, leads, deals or people, for some days (the last 30 by default), optionally with the span before it. It counts the team you lead; name one member with agent_id to see only theirs. Get the id from team_metrics_catalog. Read-only.')]
final class TeamMetric extends MetricTool
{
    protected function policy(): McpPolicy
    {
        return new TeamLeaderPolicy;
    }
}
