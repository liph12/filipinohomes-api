<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\ReportContext;
use App\Services\Reports\Executive\RowHydrator;

/**
 * Which teams use FH. Every figure is folded from agent-level counts onto the
 * one team each agent is credited to (TeamDirectory), so the teams add up to
 * the platform's totals; a "No team" row carries everyone else.
 */
final class TeamsSection
{
    public function __construct(private readonly RowHydrator $rows) {}

    public function build(ReportContext $c): array
    {
        $values = [
            'members' => $c->byTeam(array_fill_keys(array_keys($c->agents('agents.headcount')), 1)),
            'active' => $c->byTeam(array_fill_keys(array_keys($c->agents('agents.active')), 1)),
            'listings' => $c->byTeam(ReportContext::countBy($c->listingsAdded(), 'agent_id')),
            'deals' => $c->byTeam(ReportContext::countBy($c->deals(), 'agent_id')),
            'inquiries' => $c->byTeam(ReportContext::countBy($c->inquiries(), 'agent_id')),
            'hanging' => $c->byTeam(ReportContext::countBy($c->lifecycle()->whereIn('bucket', MetricEngine::HANGING), 'agent_id')),
        ];

        $teamIds = array_keys($c->teams()->teams());
        $rows = [];
        foreach ($this->rows->teams($teamIds, $values['members'], $values['active']) as $row) {
            $rows[] = $this->withValues($row, $row['team']['id'], $values);
        }
        $rows[] = $this->withValues([
            'team' => ['id' => 0, 'name' => 'No team'],
            'leaders' => null,
            'members' => $values['members'][0] ?? 0,
            'active' => $values['active'][0] ?? 0,
            'filters' => ['team_id' => 0],
        ], 0, $values);

        $rows = array_values(array_filter($rows, fn ($row) => $row['members'] + $row['active'] + $row['listings'] + $row['deals'] + $row['inquiries'] + $row['hanging'] > 0));
        usort($rows, fn ($a, $b) => [$a['team']['id'] === 0, $b['active'], $b['deals'], $b['listings'], (string) $a['team']['name']]
            <=> [$b['team']['id'] === 0, $a['active'], $a['deals'], $a['listings'], (string) $b['team']['name']]);

        return [
            'stats' => [
                $c->stat('teams.total'),
                $c->stat('teams.active', compare: true),
                $c->stat('teams.inactive'),
            ],
            'table' => [
                'columns' => [
                    Table::col('team', 'Team', 'team'),
                    Table::col('leaders', 'Leader', 'text'),
                    Table::col('members', 'Agents', 'number', 'agents.headcount'),
                    Table::col('active', 'Active', 'number', 'agents.active'),
                    Table::col('listings', 'Listings added', 'number', 'listings.added'),
                    Table::col('deals', 'Deals', 'number', 'deals.total'),
                    Table::col('inquiries', 'Inquiries', 'number', 'inquiries.received'),
                    Table::col('hanging', 'Hanging', 'number', 'inquiries.hanging.total'),
                ],
                'rows' => $rows,
            ],
        ];
    }

    private function withValues(array $row, int $teamId, array $values): array
    {
        foreach (['listings', 'deals', 'inquiries', 'hanging'] as $column) {
            $row[$column] = $values[$column][$teamId] ?? 0;
        }

        return $row;
    }
}
