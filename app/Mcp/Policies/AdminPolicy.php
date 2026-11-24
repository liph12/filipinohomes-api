<?php

namespace App\Mcp\Policies;

use App\Mcp\Support\McpActor;
use App\Mcp\Support\McpRole;
use App\Services\FhOne\BreakdownService;
use App\Services\FhOne\LookupService;
use App\Services\Reports\Executive\MetricRegistry;

/**
 * An admin reads the company's reports: any metric, every filter, every split,
 * every kind of lookup, with nothing forced on. Still read-only, and still
 * without a phone number or an email address anywhere (McpOutput).
 */
final class AdminPolicy implements McpPolicy
{
    public function role(): McpRole
    {
        return McpRole::Admin;
    }

    public function allowsMetric(string $metric): bool
    {
        return MetricRegistry::has($metric);
    }

    public function filters(): array
    {
        return ['team_id', 'agent_id', 'office_region', 'psa_region', 'island', 'province_id', 'city_id', 'project_id', 'type_id', 'category', 'status'];
    }

    public function forced(McpActor $actor): array
    {
        return [];
    }

    public function constrain(array $filters, McpActor $actor): array
    {
        return $filters;
    }

    public function dimensions(): array
    {
        return array_keys(BreakdownService::DIMENSIONS);
    }

    public function lookups(): array
    {
        return array_keys(LookupService::TYPES);
    }
}
