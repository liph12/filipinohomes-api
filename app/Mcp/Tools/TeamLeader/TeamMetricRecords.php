<?php

namespace App\Mcp\Tools\TeamLeader;

use App\Mcp\Policies\McpPolicy;
use App\Mcp\Policies\TeamLeaderPolicy;
use App\Mcp\Tools\Kinds\MetricRecordsTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('team_metric_records')]
#[Title('The records behind my team\'s number')]
#[Description('The records behind one of YOUR TEAM\'s numbers, a page at a time: use metric listings.total for all the team\'s listings, listings.live for the live ones. Contact details are never included. Read-only.')]
final class TeamMetricRecords extends MetricRecordsTool
{
    protected function policy(): McpPolicy
    {
        return new TeamLeaderPolicy;
    }
}
