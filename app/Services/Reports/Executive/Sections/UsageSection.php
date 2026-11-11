<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\ReportContext;

/** Who uses FH: people overall, by role, and how many were active in the dates. */
final class UsageSection
{
    private const ROLES = ['client', 'agent', 'admin', 'editor', 'secretary'];

    public function __construct(private readonly MetricEngine $engine) {}

    public function build(ReportContext $c): array
    {
        $total = $this->byRole('usage.total_users', $c);
        $active = $this->byRole('usage.active_users', $c);

        $rows = [];
        foreach (array_values(array_unique([...self::ROLES, ...array_keys($total), ...array_keys($active)])) as $role) {
            if (($total[$role] ?? 0) === 0 && ($active[$role] ?? 0) === 0) {
                continue;
            }
            $rows[] = [
                'role' => ucfirst((string) $role),
                'total' => $total[$role] ?? 0,
                'active' => $active[$role] ?? 0,
                'filters' => ['role' => (string) $role],
            ];
        }

        return [
            'stats' => [
                $c->stat('usage.total_users'),
                $c->stat('usage.active_users', compare: true),
                $c->stat('usage.active_clients', compare: true),
                $c->stat('usage.new_clients', compare: true),
                $c->stat('usage.returning_clients'),
                $c->stat('usage.unfinished_signups'),
            ],
            'by_role' => [
                'columns' => [
                    Table::col('role', 'Role', 'text'),
                    Table::col('total', 'Users', 'number', 'usage.total_users'),
                    Table::col('active', 'Active in these dates', 'number', 'usage.active_users'),
                ],
                'rows' => $rows,
            ],
        ];
    }

    /** [role => users] over the metric's own builder; the drill's `role` filter is its inverse. */
    private function byRole(string $key, ReportContext $c): array
    {
        [$base] = $this->engine->source($key, $c->range, $c->now);

        return (clone $base)
            ->selectRaw('r.name as d, COUNT(DISTINCT u.id) as n')
            ->groupBy('r.name')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->d => (int) $row->n])
            ->all();
    }
}
