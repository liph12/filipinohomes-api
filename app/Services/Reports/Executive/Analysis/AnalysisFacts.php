<?php

namespace App\Services\Reports\Executive\Analysis;

use App\Services\Reports\Executive\MetricRegistry;

/**
 * The facts the AI analysis may use — every number it writes is one of these.
 *
 * Taken from the built summary: each tile, plus the leaders of the breakdowns
 * (top regions, towns, teams, closers, property types, listings). A fact keeps
 * the metric and filters it came from, so a number in the analysis opens the
 * same list the tile or cell would; the id → drill mapping lives here, on the
 * server, and the model only ever writes ids.
 *
 * Ids are short snake_case names ("agents_dormant_30d", "town_1_deals") the
 * model can read. Names of people and places are facts too ({id.name}), so
 * the model never has to retype one that contains a digit. Each fact also
 * carries `say` — how to write it in a sentence ("{town_1_deals} deals in
 * {town_1_deals.name}") — because a placeholder renders as a bare number and
 * the words around it must say what it counts.
 */
final class AnalysisFacts
{
    private const REGIONS = 5;

    private const TOWNS = 5;

    private const TEAMS = 3;

    private const AGENTS = 3;

    private const TYPES = 3;

    private const LISTINGS = 3;

    /** @return array{meta: array, facts: array<string, array>} */
    public function fromSummary(array $summary): array
    {
        $facts = [];
        $s = $summary['sections'];

        $stats = $summary['kpis'];
        foreach ($s as $section) {
            array_push($stats, ...($section['stats'] ?? []), ...($section['hanging'] ?? []), ...($section['claims'] ?? []));
        }
        foreach ($stats as $stat) {
            $id = str_replace('.', '_', $stat['key']);
            if (isset($facts[$id])) {
                continue;
            }
            // A range that starts before FH kept this data has no fair comparison — the
            // model isn't given one, so it can't write one ({id.prev} won't resolve).
            $partial = $stat['partial'] || ($stat['previous_partial'] ?? false);
            $ai = MetricRegistry::get($stat['key'])['ai'];
            $facts[$id] = [
                'label' => $stat['label'],
                'say' => $stat['unit'] === 'count' ? "{{$id}} {$ai}" : "{$ai}, {{$id}}",
                'value' => $stat['value'],
                'previous' => $partial ? null : ($stat['previous'] ?? null),
                'change_pct' => $partial ? null : ($stat['change_pct'] ?? null),
                'sample' => $stat['sample'] ?? null,
                'unit' => $stat['unit'],
                'as_of' => $stat['as_of'],
                'partial' => $partial,
                'metric' => $stat['key'],
                'filters' => [],
            ];
        }

        foreach (array_slice($s['inquiries']['by_region']['psa'], 0, self::REGIONS) as $i => $row) {
            $id = 'region_'.($i + 1);
            $facts[$id] = self::cell("Inquiries from {$row['label']} (PSA region)", "{{$id}} inquiries from {{$id}.name}", $row['label'], $row['value'], 'inquiries.received', $row['filters']);
        }

        foreach (array_slice($s['places']['cities']['rows'], 0, self::TOWNS) as $i => $row) {
            $n = $i + 1;
            $place = $row['city'].($row['province'] ? ", {$row['province']}" : '');
            $facts["town_{$n}_listings"] = self::cell("Listings added in {$place}", "{town_{$n}_listings} listings added in {town_{$n}_listings.name}", $row['city'], $row['listings'], 'listings.added', $row['filters']);
            $facts["town_{$n}_inquiries"] = self::cell("Inquiries on listings in {$place}", "{town_{$n}_inquiries} inquiries on listings in {town_{$n}_inquiries.name}", $row['city'], $row['inquiries'], 'inquiries.received', $row['filters']);
            $facts["town_{$n}_deals"] = self::cell("Deals in {$place}", "{town_{$n}_deals} deals in {town_{$n}_deals.name}", $row['city'], $row['deals'], 'deals.total', $row['filters']);
        }

        $teams = array_values(array_filter($s['teams']['table']['rows'], fn ($r) => $r['team']['id'] !== 0));
        foreach (array_slice($teams, 0, self::TEAMS) as $i => $row) {
            $n = $i + 1;
            $name = (string) $row['team']['name'];
            $facts["team_{$n}_active"] = self::cell("Active agents in team {$name} (of {$row['members']})", "{team_{$n}_active} active agents in team {team_{$n}_active.name}", $name, $row['active'], 'agents.active', $row['filters']);
            $facts["team_{$n}_deals"] = self::cell("Deals by team {$name}", "{team_{$n}_deals} deals by team {team_{$n}_deals.name}", $name, $row['deals'], 'deals.total', $row['filters']);
            $facts["team_{$n}_hanging"] = self::cell("Hanging inquiries for team {$name}", "{team_{$n}_hanging} hanging inquiries for team {team_{$n}_hanging.name}", $name, $row['hanging'], 'inquiries.hanging.total', $row['filters']);
        }

        $board = collect($s['agents']['leaderboard']['rows'])->keyBy(fn ($r) => $r['agent']['id']);
        $tabs = [
            'closers' => ['deals', 'deals.total', 'Deals closed by agent', 'deals closed by'],
            'posters' => ['listings', 'listings.added', 'Listings added by agent', 'listings added by'],
            'hanging' => ['hanging', 'inquiries.hanging.total', 'Hanging inquiries for agent', 'hanging inquiries for'],
        ];
        foreach ($tabs as $tab => [$column, $metric, $label, $words]) {
            foreach (array_slice($s['agents']['leaderboard']['tops'][$tab], 0, self::AGENTS) as $i => $agentId) {
                $row = $board->get($agentId);
                if ($row !== null && $row[$column] > 0) {
                    $id = "{$tab}_".($i + 1);
                    $name = (string) $row['agent']['name'];
                    $facts[$id] = self::cell("{$label} {$name}", "{{$id}} {$words} {{$id}.name}", $name, $row[$column], $metric, $row['filters']);
                }
            }
        }

        foreach (array_slice($s['deals']['by']['type']['rows'], 0, self::TYPES) as $i => $row) {
            $id = 'deals_type_'.($i + 1);
            $facts[$id] = self::cell("Deals on {$row['label']} listings", "{{$id}} deals on {{$id}.name} listings", (string) $row['label'], $row['total'], 'deals.total', $row['filters']);
        }

        foreach (array_slice($s['inquiries']['top_listings']['rows'], 0, self::LISTINGS) as $i => $row) {
            $id = 'listing_'.($i + 1).'_inquiries';
            $name = (string) ($row['listing']['name'] ?? 'Listing');
            $facts[$id] = self::cell("Inquiries on the listing \"{$name}\"", "{{$id}} inquiries on the listing {{$id}.name}", $name, $row['inquiries'], 'inquiries.received', $row['filters']);
        }

        return [
            'meta' => [
                'range' => ['start' => $summary['range']['start'], 'end' => $summary['range']['end']],
                'previous' => $summary['range']['previous'],
            ],
            'facts' => $facts,
        ];
    }

    /** What the facts say, independent of when they were read — equal facts, equal hash. */
    public static function hash(array $facts): string
    {
        return hash('sha256', json_encode($facts['facts']));
    }

    private static function cell(string $label, string $say, string $name, int|float|null $value, string $metric, array $filters): array
    {
        return [
            'label' => $label,
            'say' => $say,
            'name' => $name,
            'value' => $value,
            'previous' => null,
            'change_pct' => null,
            'sample' => null,
            'unit' => 'count',
            'as_of' => str_starts_with($metric, 'inquiries.hanging') ? 'now' : 'range',
            'partial' => false,
            'metric' => $metric,
            'filters' => $filters,
        ];
    }
}
