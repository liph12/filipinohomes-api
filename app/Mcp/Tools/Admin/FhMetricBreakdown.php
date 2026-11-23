<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricBreakdownTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('fh_metric_breakdown')]
#[Title('Filipino Homes: a number split')]
#[Description('A company number split by team, office region, project, city, province, category, type or agent: the biggest rows first, like the top 10 teams by sold. Exact, from FH\'s AI Report. Read-only.')]
final class FhMetricBreakdown extends MetricBreakdownTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }
}
