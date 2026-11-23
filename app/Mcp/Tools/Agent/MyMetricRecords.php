<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\Policies\AgentPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricRecordsTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('my_metric_records')]
#[Title('My Filipino Homes records')]
#[Description('The records behind one of YOUR OWN numbers, a page at a time: use metric listings.total for all your listings, listings.live for the live ones. Contact details are never included. Read-only.')]
final class MyMetricRecords extends MetricRecordsTool
{
    protected function policy(): McpPolicy
    {
        return new AgentPolicy;
    }
}
