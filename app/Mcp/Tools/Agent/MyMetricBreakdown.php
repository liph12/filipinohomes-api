<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\Policies\AgentPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricBreakdownTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('my_metric_breakdown')]
#[Title('My Filipino Homes number, split')]
#[Description('One of YOUR OWN numbers split by project, city, province, category or type, biggest first. It only ever counts your own work. Read-only.')]
final class MyMetricBreakdown extends MetricBreakdownTool
{
    protected function policy(): McpPolicy
    {
        return new AgentPolicy;
    }
}
