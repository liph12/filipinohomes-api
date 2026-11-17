<?php

namespace App\Services\FhOne;

use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\MetricEngine;
use App\Services\Reports\Executive\MetricRegistry;
use App\Services\Reports\Executive\ReportContext;
use App\Services\Reports\Executive\ReportQueries;
use App\Services\Reports\Executive\ReportRange;
use App\Services\Reports\Executive\TeamDirectory;
use App\Support\OfficeRegionMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A number split by team, project, town and so on ("sold, by team").
 *
 * It groups the very builder MetricEngine::source() hands the report and the
 * drill, then folds the raw ids the way the report does (towns, provinces,
 * the one team an agent is credited to), so a cell counts the records its own
 * drill lists. Every row carries the filter that opens those records.
 *
 * Each cell also says how many different agents are behind it ("agents
 * selling"), which needs the agent next to the group: rows are grouped by
 * both, then folded.
 */
final class BreakdownService
{
    /** @var array<string, array{label: string, filter: string, lookup: string|null}> */
    public const DIMENSIONS = [
        'team' => ['label' => 'Team', 'filter' => 'team_id', 'lookup' => 'teams'],
        'office_region' => ['label' => 'Office region', 'filter' => 'office_region', 'lookup' => 'regions'],
        'project' => ['label' => 'Project', 'filter' => 'project_id', 'lookup' => 'projects'],
        'city' => ['label' => 'Town / city', 'filter' => 'city_id', 'lookup' => 'cities'],
        'province' => ['label' => 'Province', 'filter' => 'province_id', 'lookup' => 'provinces'],
        'category' => ['label' => 'Category', 'filter' => 'category', 'lookup' => null],
        'type' => ['label' => 'Property type', 'filter' => 'type_id', 'lookup' => 'types'],
        'agent' => ['label' => 'Agent', 'filter' => 'agent_id', 'lookup' => null],
    ];

    /** The "none" bucket of a dimension: the cell for records that have no value. */
    private const NONE = [
        'team' => ['key' => 0, 'label' => 'No team'],
        'office_region' => ['key' => 'unassigned', 'label' => 'No office region'],
        'project' => ['key' => 0, 'label' => 'Not in a project'],
        'city' => ['key' => 0, 'label' => 'No city'],
        'province' => ['key' => 0, 'label' => 'No province'],
        'type' => ['key' => 0, 'label' => 'Unspecified type'],
    ];

    /** Dimension → the ReportQueries dimension that groups it in SQL (the rest fold from those). */
    private const SQL_DIMENSION = [
        'team' => 'agent', 'agent' => 'agent', 'office_region' => 'office', 'project' => 'project',
        'city' => 'city', 'province' => 'province', 'category' => 'category', 'type' => 'type',
    ];

    public function __construct(
        private readonly MetricEngine $engine,
        private readonly ReportQueries $q,
        private readonly Geography $geo,
        private readonly TeamDirectory $teams,
    ) {}

    /**
     * What a metric can be split by. Only counts can: a median or a total of
     * people isn't a sum of its parts. The inquiry lifecycle and the lead
     * sources are grouped by the agent (or the office) their rows carry.
     *
     * @return list<string>
     */
    public function dimensionsFor(string $key): array
    {
        $def = MetricRegistry::get($key);

        if ($def['kind'] !== 'count') {
            return [];
        }

        return match ($def['family']) {
            'listing' => array_keys(self::DIMENSIONS),
            'lifecycle' => ['team', 'agent'],
            'lead' => ['team', 'office_region', 'agent'],
            'agent' => ['team', 'office_region'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $filters  already checked against the metric
     * @return array<string, mixed>
     *
     * @throws InvalidAsk when the metric can't be split that way
     */
    public function build(string $key, string $by, ReportRange $range, Carbon $now, array $filters, int $top): array
    {
        if (! in_array($by, $this->dimensionsFor($key), true)) {
            throw new InvalidAsk("'{$key}' can't be broken down by '{$by}'.", 'by');
        }

        $dimension = self::DIMENSIONS[$by];
        $cells = $this->fold($by, $this->pairs($key, $by, $range, $now, $filters));

        $none = self::NONE[$by] ?? null;
        $noneCell = null;
        if ($none !== null && isset($cells[$none['key']])) {
            $noneCell = $cells[$none['key']];
            unset($cells[$none['key']]);
        }

        $order = ReportContext::ranked(array_map(fn (array $cell) => $cell['value'], $cells));
        $shown = array_slice($order, 0, $top);
        $rest = array_slice($order, $top);
        $labels = $this->labels($by, $shown);

        $row = fn (int|string $cellKey, array $cell, string $label) => [
            'key' => $cellKey,
            'label' => $label,
            'value' => $cell['value'],
            'agents' => count($cell['agents']),
            'filters' => [$dimension['filter'] => $cellKey],
        ];

        $rows = array_map(fn ($cellKey) => $row($cellKey, $cells[$cellKey], $labels[$cellKey]), $shown);

        $others = null;
        if ($rest !== []) {
            $agents = [];
            foreach ($rest as $cellKey) {
                $agents += $cells[$cellKey]['agents'];
            }
            $others = [
                'groups' => count($rest),
                'value' => array_sum(array_map(fn ($cellKey) => $cells[$cellKey]['value'], $rest)),
                'agents' => count($agents),
            ];
        }

        $cellsTotal = array_sum(array_column($cells, 'value')) + ($noneCell['value'] ?? 0);
        $total = (int) $this->engine->value($key, $range, $now, $filters);

        return [
            'by' => $by,
            'dimension' => $dimension,
            'top' => $top,
            'rows' => $rows,
            'none' => $noneCell === null ? null : $row($none['key'], $noneCell, $none['label']),
            'others' => $others,
            // The metric's own number, and what the cells add up to. They differ only when some
            // records have no agent (a brief on an admin's link): those count in no team, as with
            // the team filter, and `unattributed` is how many.
            'total' => $total,
            'cells_total' => $cellsTotal,
            'unattributed' => max(0, $total - $cellsTotal),
            'reconciled' => $cellsTotal === $total,
        ];
    }

    // ── Rows ────────────────────────────────────────────────────────────────

    /**
     * The metric's records grouped by (dimension value, agent), counted.
     *
     * @return list<array{d: string, a: string, n: int}>
     */
    private function pairs(string $key, string $by, ReportRange $range, Carbon $now, array $filters): array
    {
        $family = MetricRegistry::get($key)['family'];

        if ($family === 'agent') {
            $rows = [];
            foreach ($this->engine->agentRegions($key, $range, $now, $filters) as $agentId => $region) {
                $rows[] = ['d' => (string) ($region ?? ''), 'a' => (string) $agentId, 'n' => 1];
            }

            return $rows;
        }

        [$base, $id] = $this->engine->source($key, $range, $now, $filters);

        $result = match ($family) {
            'lifecycle' => (clone $base)
                ->selectRaw("t.agent_id as a, t.agent_id as d, COUNT(DISTINCT {$id}) as n")
                ->groupBy('t.agent_id')->get(),
            'lead' => (clone $base)
                ->selectRaw("ld.office as d, ld.agent_id as a, COUNT(DISTINCT {$id}) as n")
                ->groupBy('ld.office', 'ld.agent_id')->get(),
            default => (function () use ($base, $id, $by) {
                $expr = $this->q->dimExpr(self::SQL_DIMENSION[$by]);

                return (clone $base)
                    ->selectRaw("{$expr} as d, listings.agent_id as a, COUNT(DISTINCT {$id}) as n")
                    ->groupBy(DB::raw($expr), 'listings.agent_id')->get();
            })(),
        };

        return $result->map(fn ($r) => ['d' => (string) ($r->d ?? ''), 'a' => (string) ($r->a ?? ''), 'n' => (int) $r->n])->all();
    }

    /**
     * Raw rows → one cell per group, each with its count and the agents behind it.
     *
     * @param  list<array{d: string, a: string, n: int}>  $pairs
     * @return array<int|string, array{value: int, agents: array<string, true>}>
     */
    private function fold(string $by, array $pairs): array
    {
        $cells = [];

        foreach ($pairs as $pair) {
            $group = $this->groupOf($by, $pair['d'], $pair['a']);

            if ($group === null) {
                continue;
            }

            $cells[$group] ??= ['value' => 0, 'agents' => []];
            $cells[$group]['value'] += $pair['n'];

            if ($pair['a'] !== '') {
                $cells[$group]['agents'][$pair['a']] = true;
            }
        }

        return $cells;
    }

    /** The group a raw row belongs to; null counts nowhere (as with the report's own team filter). */
    private function groupOf(string $by, string $d, string $a): int|string|null
    {
        return match ($by) {
            'team' => $a === '' ? null : ($this->teams->teamOf((int) $a) ?? 0),
            'agent' => $a === '' ? null : (int) $a,
            'office_region' => ReportContext::officeOf($d),
            'project', 'type' => $d === '' ? 0 : (int) $d,
            'city' => $d === '' ? 0 : ($this->geo->cityGroup((int) $d) ?? 0),
            'province' => $d === '' ? 0 : ($this->geo->canonicalProvince((int) $d) ?? 0),
            'category' => ($found = array_search($d, ReportQueries::CATEGORIES, true)) === false ? null : $found,
        };
    }

    // ── Names ───────────────────────────────────────────────────────────────

    /**
     * @param  list<int|string>  $keys
     * @return array<int|string, string>
     */
    private function labels(string $by, array $keys): array
    {
        $ids = array_values(array_filter(array_map('intval', $keys)));

        return match ($by) {
            'team' => $this->each($keys, fn ($k) => $this->teams->name((int) $k) ?? "Team #{$k}"),
            'office_region' => $this->each($keys, fn ($k) => OfficeRegionMap::label((string) $k)),
            'category' => $this->each($keys, fn ($k) => ReportQueries::CATEGORIES[$k] ?? (string) $k),
            'province' => $this->each($keys, fn ($k) => $this->geo->provinceName((int) $k) ?? "Province #{$k}"),
            'city' => $this->each($keys, fn ($k) => $this->townLabel((int) $k)),
            'project' => $this->named('projects', $ids, $keys, 'Project'),
            'type' => $this->named('property_types', $ids, $keys, 'Type'),
            'agent' => $this->agentLabels($ids, $keys),
        };
    }

    /** @param  list<int|string>  $keys */
    private function each(array $keys, callable $label): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $label($k);
        }

        return $out;
    }

    private function townLabel(int $group): string
    {
        $town = $this->geo->cityName($group);

        return $town === null ? "Town #{$group}" : implode(', ', array_unique(array_filter([
            $town,
            $this->geo->provinceName($this->geo->cityProvince($group)),
        ])));
    }

    /** @param  list<int>  $ids */
    private function named(string $table, array $ids, array $keys, string $fallback): array
    {
        $names = $ids === [] ? [] : DB::table($table)->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $this->each($keys, fn ($k) => (string) (($names[(int) $k] ?? null) ?: "{$fallback} #{$k}"));
    }

    /** @param  list<int>  $ids */
    private function agentLabels(array $ids, array $keys): array
    {
        $rows = $ids === [] ? collect() : DB::table('agents')
            ->leftJoin('users as u', 'u.id', '=', 'agents.user_id')
            ->whereIn('agents.id', $ids)
            ->get(['agents.id', 'agents.first_name', 'agents.last_name', 'u.name'])
            ->keyBy('id');

        return $this->each($keys, function ($k) use ($rows) {
            $row = $rows[(int) $k] ?? null;
            $name = $row ? trim(trim((string) $row->first_name).' '.trim((string) $row->last_name)) : '';

            return $name !== '' ? $name : ($row?->name ?: "Agent #{$k}");
        });
    }
}
