<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\ReportContext;
use Illuminate\Support\Collection;

/**
 * Listings marked sold / rented / leased in the dates, split by property
 * type, town, project and team. One row set, folded four ways — each listing
 * has one status, so a row's total is its sold + rented + leased.
 */
final class DealsSection
{
    private const TOP = 20;

    private const STATUSES = ['sold', 'rented', 'leased'];

    public function build(ReportContext $c): array
    {
        $rows = $c->deals();
        $geo = $c->geo();
        $typeNames = $c->typeNames();

        $byType = $this->split($rows, fn ($r) => (int) ($r->type_id ?? 0));
        $byCity = $this->split($rows, fn ($r) => $r->city_id === null ? 0 : ($geo->cityGroup((int) $r->city_id) ?? 0));
        $byProject = $this->split($rows, fn ($r) => (int) ($r->project_id ?? 0));
        $byTeam = $this->split(
            $rows->filter(fn ($r) => $r->agent_id !== null),
            fn ($r) => $c->teams()->teamOf((int) $r->agent_id) ?? 0,
        );
        $projectNames = $c->projectNames(array_keys($byProject));

        return [
            'stats' => [
                $c->stat('deals.total', compare: true),
                $c->stat('deals.sold', compare: true),
                $c->stat('deals.rented', compare: true),
                $c->stat('deals.leased', compare: true),
            ],
            'by' => [
                'type' => $this->table('Type', 'text', $byType, null, fn ($k) => $typeNames[$k] ?? 'Unspecified', 'type_id'),
                'city' => $this->table('Town / city', 'place', $byCity, self::TOP, fn ($k) => $k === 0 ? 'No city'
                    : implode(', ', array_unique(array_filter([$geo->cityName($k), $geo->provinceName($geo->cityProvince($k))]))), 'city_id'),
                'project' => $this->table('Project', 'text', $byProject, self::TOP, fn ($k) => $k === 0 ? 'No project' : ($projectNames[$k] ?? "Project #{$k}"), 'project_id'),
                'team' => $this->table('Team', 'team', $byTeam, null, fn ($k) => ['id' => $k, 'name' => $k === 0 ? 'No team' : $c->teams()->name($k)], 'team_id'),
            ],
        ];
    }

    /** @return array<int, array{total: int, sold: int, rented: int, leased: int}> */
    private function split(Collection $rows, callable $keyOf): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $keyOf($row);
            $out[$key] ??= ['total' => 0, 'sold' => 0, 'rented' => 0, 'leased' => 0];
            $out[$key]['total']++;
            $status = strtolower((string) $row->status);
            if (in_array($status, self::STATUSES, true)) {
                $out[$key][$status]++;
            }
        }

        return $out;
    }

    private function table(string $label, string $type, array $split, ?int $limit, callable $name, string $filter): array
    {
        $order = ReportContext::ranked(array_map(fn ($s) => $s['total'], $split), $limit);

        return [
            'columns' => [
                Table::col('label', $label, $type),
                Table::col('total', 'Deals', 'number', 'deals.total'),
                Table::col('sold', 'Sold', 'number', 'deals.sold'),
                Table::col('rented', 'Rented', 'number', 'deals.rented'),
                Table::col('leased', 'Leased', 'number', 'deals.leased'),
            ],
            'rows' => array_map(fn ($key) => ['label' => $name($key)] + $split[$key] + ['filters' => [$filter => $key]], $order),
            'more' => $limit !== null ? max(0, count($split) - $limit) : 0,
        ];
    }
}
