<?php

namespace App\Services\Reports\Executive\Analysis;

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
 * the model never has to retype one that contains a digit.
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
            // A range that starts before FH kept this data has no fair comparison — the
            // model isn't given one, so it can't write one ({id.prev} won't resolve).
            $partial = $stat['partial'] || ($stat['previous_partial'] ?? false);
            $facts[str_replace('.', '_', $stat['key'])] ??= [
                'label' => $stat['label'],
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
            $facts['region_'.($i + 1)] = self::cell("Inquiries from {$row['label']} (PSA region)", $row['label'], $row['value'], 'inquiries.received', $row['filters']);
        }

        foreach (array_slice($s['places']['cities']['rows'], 0, self::TOWNS) as $i => $row) {
            $n = $i + 1;
            $place = $row['city'].($row['province'] ? ", {$row['province']}" : '');
            $facts["town_{$n}_listings"] = self::cell("Listings added in {$place}", $row['city'], $row['listings'], 'listings.added', $row['filters']);
            $facts["town_{$n}_inquiries"] = self::cell("Inquiries on listings in {$place}", $row['city'], $row['inquiries'], 'inquiries.received', $row['filters']);
            $facts["town_{$n}_deals"] = self::cell("Deals in {$place}", $row['city'], $row['deals'], 'deals.total', $row['filters']);
        }

        $teams = array_values(array_filter($s['teams']['table']['rows'], fn ($r) => $r['team']['id'] !== 0));
        foreach (array_slice($teams, 0, self::TEAMS) as $i => $row) {
            $n = $i + 1;
            $name = (string) $row['team']['name'];
            $facts["team_{$n}_active"] = self::cell("Active agents in team {$name} (of {$row['members']})", $name, $row['active'], 'agents.active', $row['filters']);
            $facts["team_{$n}_deals"] = self::cell("Deals by team {$name}", $name, $row['deals'], 'deals.total', $row['filters']);
            $facts["team_{$n}_hanging"] = self::cell("Hanging inquiries for team {$name}", $name, $row['hanging'], 'inquiries.hanging.total', $row['filters']);
        }

        $board = collect($s['agents']['leaderboard']['rows'])->keyBy(fn ($r) => $r['agent']['id']);
        foreach (['closers' => ['deals', 'deals.total', 'Deals closed by'], 'posters' => ['listings', 'listings.added', 'Listings added by'], 'hanging' => ['hanging', 'inquiries.hanging.total', 'Hanging inquiries for']] as $tab => [$column, $metric, $label]) {
            foreach (array_slice($s['agents']['leaderboard']['tops'][$tab], 0, self::AGENTS) as $i => $agentId) {
                $row = $board->get($agentId);
                if ($row !== null && $row[$column] > 0) {
                    $name = (string) $row['agent']['name'];
                    $facts["{$tab}_".($i + 1)] = self::cell("{$label} agent {$name}", $name, $row[$column], $metric, $row['filters']);
                }
            }
        }

        foreach (array_slice($s['deals']['by']['type']['rows'], 0, self::TYPES) as $i => $row) {
            $facts['deals_type_'.($i + 1)] = self::cell("Deals on {$row['label']} listings", (string) $row['label'], $row['total'], 'deals.total', $row['filters']);
        }

        foreach (array_slice($s['inquiries']['top_listings']['rows'], 0, self::LISTINGS) as $i => $row) {
            $name = (string) ($row['listing']['name'] ?? 'Listing');
            $facts['listing_'.($i + 1).'_inquiries'] = self::cell("Inquiries on the listing \"{$name}\"", $name, $row['inquiries'], 'inquiries.received', $row['filters']);
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

    private static function cell(string $label, string $name, int|float|null $value, string $metric, array $filters): array
    {
        return [
            'label' => $label,
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
