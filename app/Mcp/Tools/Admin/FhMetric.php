<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('fh_metric')]
#[Title('Filipino Homes: a company number')]
#[Description('One company-wide number for some days (the last 30 by default), optionally with the span before it. Exact: it is the figure in FH\'s AI Report. Get the id from fh_metrics_catalog. Read-only.')]
final class FhMetric extends MetricTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }
}
