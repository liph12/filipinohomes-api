<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\Policies\AgentPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('my_metric')]
#[Title('My Filipino Homes number')]
#[Description('One number about YOUR OWN listings, leads or deals, for some days (the last 30 by default), optionally with the span before it. It only ever counts your own work. Get the id from my_metrics_catalog. Read-only.')]
final class MyMetric extends MetricTool
{
    protected function policy(): McpPolicy
    {
        return new AgentPolicy;
    }
}
