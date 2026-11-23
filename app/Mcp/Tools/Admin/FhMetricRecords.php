<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Policies\AdminPolicy;
use App\Mcp\Policies\McpPolicy;
use App\Mcp\Tools\Kinds\MetricRecordsTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('fh_metric_records')]
#[Title('Filipino Homes: the records behind a number')]
#[Description('The records a number counts (listings, inquiries, deals), a page at a time, so an answer can be checked. Contact details are never included. Read-only.')]
final class FhMetricRecords extends MetricRecordsTool
{
    protected function policy(): McpPolicy
    {
        return new AdminPolicy;
    }
}
