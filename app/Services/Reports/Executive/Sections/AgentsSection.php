<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\ReportContext;
use App\Services\Reports\Executive\RowHydrator;
use App\Support\OfficeRegionMap;

/**
 * Which agents work FH: the activity split, a leaderboard (top posters,
 * closers, most-inquired, most-hanging) and the roster by office region.
 *
 * Rankings hold agent-role agents only — admins, editors and secretaries also
 * own listings through agent rows, and would otherwise crowd them (the
 * Business Pulse's rule). Their listings still count in every total.
 */
final class AgentsSection
{
    private const TOP = 10;

    /** Leaderboard tab => the measure it ranks by. */
    private const TOPS = ['posters' => 'listings', 'closers' => 'deals', 'inquired' => 'inquiries', 'hanging' => 'hanging'];

    /** By-office column => metric. */
    private const OFFICE_COLUMNS = [
        'headcount' => 'agents.headcount',
        'active' => 'agents.active',
        'idle' => 'agents.idle',
        'dormant' => 'agents.dormant_30d',
        'posting' => 'agents.posting',
    ];

    public function __construct(private readonly RowHydrator $rows) {}

    public function build(ReportContext $c): array
    {
        return [
            'stats' => [
                $c->stat('agents.headcount'),
                $c->stat('agents.active', compare: true),
                $c->stat('agents.idle'),
                $c->stat('agents.dormant_30d'),
                $c->stat('agents.posting', compare: true),
                $c->stat('agents.logged_in', compare: true),
                $c->stat('agents.new'),
            ],
            'leaderboard' => $this->leaderboard($c),
            'by_office' => $this->byOffice($c),
        ];
    }

    private function leaderboard(ReportContext $c): array
    {
        $measures = [
            'listings' => ReportContext::countBy($c->listingsAdded(), 'agent_id'),
            'deals' => ReportContext::countBy($c->deals(), 'agent_id'),
            'inquiries' => ReportContext::countBy($c->inquiries(), 'agent_id'),
            'hanging' => ReportContext::countBy($c->lifecycle()->whereIn('bucket', MetricEngine::HANGING), 'agent_id'),
        ];

        $roleAgents = $c->agentRoleIds();
        $tops = [];
        foreach (self::TOPS as $tab => $measure) {
            $eligible = array_filter(
                $measures[$measure],
                fn ($n, $agent) => $agent !== '' && isset($roleAgents[(int) $agent]),
                ARRAY_FILTER_USE_BOTH,
            );
            $tops[$tab] = array_map('intval', ReportContext::ranked($eligible, self::TOP));
        }

        $ids = array_values(array_unique(array_merge(...array_values($tops))));
        $people = collect($this->rows->agents($ids))->keyBy(fn ($row) => $row['agent']['id']);

        $rows = [];
        foreach ($ids as $id) {
            $person = $people->get($id);
            if ($person === null) {
                continue;
            }
            $rows[] = [
                'agent' => $person['agent'],
                'office' => $person['office'],
                'listings' => $measures['listings'][$id] ?? 0,
                'deals' => $measures['deals'][$id] ?? 0,
                'inquiries' => $measures['inquiries'][$id] ?? 0,
                'hanging' => $measures['hanging'][$id] ?? 0,
                'filters' => ['agent_id' => $id],
            ];
        }

        return [
            'columns' => [
                Table::col('agent', 'Agent', 'agent'),
                Table::col('office', 'Office region', 'text'),
                Table::col('listings', 'Listings added', 'number', 'listings.added'),
                Table::col('deals', 'Deals', 'number', 'deals.total'),
                Table::col('inquiries', 'Inquiries', 'number', 'inquiries.received'),
                Table::col('hanging', 'Hanging', 'number', 'inquiries.hanging.total'),
            ],
            'rows' => $rows,
            'tops' => $tops,
        ];
    }

    private function byOffice(ReportContext $c): array
    {
        $counts = [];
        foreach (self::OFFICE_COLUMNS as $column => $key) {
            $regions = array_map(fn ($region) => (string) $region, array_values($c->agents($key)));
            $counts[$column] = ReportContext::byOffice(array_count_values($regions));
        }

        $rows = [];
        foreach ([...OfficeRegionMap::REGIONS, 'unassigned'] as $office) {
            $row = ['office' => $office === 'unassigned' ? 'No office region' : OfficeRegionMap::label($office)];
            foreach (array_keys(self::OFFICE_COLUMNS) as $column) {
                $row[$column] = $counts[$column][$office] ?? 0;
            }
            if (array_sum(array_slice($row, 1)) > 0) {
                $rows[] = $row + ['filters' => ['office_region' => $office]];
            }
        }
        usort($rows, fn ($a, $b) => [$b['headcount'], $b['active']] <=> [$a['headcount'], $a['active']]);

        return [
            'columns' => [
                Table::col('office', 'Office region', 'text'),
                Table::col('headcount', 'Agents', 'number', 'agents.headcount'),
                Table::col('active', 'Active', 'number', 'agents.active'),
                Table::col('idle', 'Idle', 'number', 'agents.idle'),
                Table::col('dormant', 'Dormant 30+ days', 'number', 'agents.dormant_30d'),
                Table::col('posting', 'Posted listings', 'number', 'agents.posting'),
            ],
            'rows' => $rows,
        ];
    }
}
