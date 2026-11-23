<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricsCatalogTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('fh_metrics_catalog')]
#[Title('Filipino Homes: list the numbers')]
#[Description('List the numbers Filipino Homes can report: each one\'s id, what it counts, its unit, and the filters and splits it takes. Start here to find the id for a question. Read-only: nothing in these tools can change or delete anything.')]
final class FhMetricsCatalog extends MetricsCatalogTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }
}
