<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\Policies\AgentPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricsCatalogTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('my_metrics_catalog')]
#[Title('My Filipino Homes numbers: what I can ask')]
#[Description('List the numbers about your own listings, leads and deals that you can ask for, with what each counts and which filters and splits it takes. Start here to find the id for a question. Read-only.')]
final class MyMetricsCatalog extends MetricsCatalogTool
{
    protected function policy(): McpPolicy
    {
        return new AgentPolicy;
    }
}
