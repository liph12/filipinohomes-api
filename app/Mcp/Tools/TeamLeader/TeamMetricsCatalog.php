<?php

namespace App\Mcp\Tools\TeamLeader;

use App\Mcp\Policies\McpPolicy;
use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Tools\Kinds\MetricsCatalogTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('team_metrics_catalog')]
#[Title('My team\'s numbers: what I can ask')]
#[Description('List the numbers about your team\'s listings, leads, deals and people that you can ask for, with what each counts and which filters and splits it takes. Start here to find the id for a question about your team. Read-only.')]
final class TeamMetricsCatalog extends MetricsCatalogTool
{
    protected function policy(): McpPolicy
    {
        return new TeamLeaderPolicy;
    }
}
