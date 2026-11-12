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

    /**
     * The facts for the Agents page (AgentReportService::build()): its tiles,
     * the answer and working rates, how leads spread, the top office regions
     * and the Cebu spotlight, the most and least active teams, "No team", and
     * the agents with the most leads.
     *
     * @return array{meta: array, facts: array<string, array>}
     */
    public function fromAgents(array $page): array
    {
        $facts = [];
        $stats = [
            ...$page['overview']['stats'], $page['overview']['roster'], $page['overview']['reached'], $page['overview']['first_reply'],
            ...$page['leads']['stats'], ...$page['leads']['sources'], ...$page['teams']['stats'], ...$page['quiet']['stats'],
        ];
        foreach ($stats as $stat) {
            $id = str_replace('.', '_', $stat['key']);
            $facts[$id] ??= self::stat($stat, $id);
        }
        $value = fn (string $id) => $facts[$id]['value'] ?? null;

        $facts['leads_answered_rate'] = self::percent('Share of chat leads answered', '{leads_answered_rate} of chat leads were answered', self::rate($value('leads_answered'), $value('leads_reached')), 'now', 'up');
        $facts['agents_working_rate'] = self::percent('Share of roster agents working', '{agents_working_rate} of agents on the roster did something on FH', self::rate($value('agents_active'), $value('agents_headcount')), 'range', 'up');

        foreach ($page['leads']['spread'] as $b) {
            $id = 'spread_'.str_replace('-', '_', $b['bucket']);
            $facts[$id] = self::cell("Agents who got {$b['label']} in the period", "{{$id}} agents got {{$id}.name}", $b['label'], $b['agents'], 'agents.with_leads', $b['filters']);
        }

        // The spotlight (Cebu) under its own name, then the next biggest regions — never one region twice.
        $spot = $page['regions']['spotlight'];
        $spotId = str_replace('-', '_', $spot['key']);
        if ($spot['row'] !== null) {
            $this->region($facts, $spotId, $spot['row']);
        }
        if ($spot['property'] !== null) {
            $p = $spot['property'];
            $facts["{$spotId}_property_inquiries"] = self::cell("Inquiries about properties in {$p['province']}", "{{$spotId}_property_inquiries} inquiries about properties in {{$spotId}_property_inquiries.name}", (string) $p['province'], $p['value'], $p['metric'], $p['filters']);
            $facts["{$spotId}_property_share"] = self::percent("Share of all inquiries that were about {$p['province']} properties", "{{$spotId}_property_share} of all inquiries", self::rate($p['value'], $p['of']), 'range', 'neutral');
        }
        $regions = array_values(array_filter($page['regions']['table']['rows'], fn ($r) => ! in_array($r['key'], ['unassigned', $spot['key']], true)));
        foreach (array_slice($regions, 0, self::REGIONS) as $i => $row) {
            $this->region($facts, 'region_'.($i + 1), $row);
        }
        // "No office region" / "No team" aren't names: without one, the AI can't write "the No team agents".
        $facts['agents_no_office'] = self::cell('Roster agents with no office region', '{agents_no_office} agents have no office region', null, $page['regions']['unassigned'], 'agents.headcount', ['office_region' => 'unassigned']);

        foreach (['most_active' => 'team_top', 'least_active' => 'team_low'] as $list => $prefix) {
            foreach (array_slice($page['teams'][$list], 0, self::TEAMS) as $i => $row) {
                $this->team($facts, $prefix.'_'.($i + 1), $row);
            }
        }
        $noTeam = $page['teams']['no_team'];
        $facts['no_team_agents'] = self::cell('Roster agents in no team', '{no_team_agents} agents are in no team', null, $noTeam['members'], 'agents.headcount', $noTeam['filters']);
        $facts['no_team_leads'] = self::cell('Leads that went to agents in no team', '{no_team_leads} leads went to agents in no team', null, $noTeam['leads'], 'leads.all', $noTeam['filters']);
        $facts['no_team_waiting'] = self::cell('Never-answered leads of agents in no team', '{no_team_waiting} leads to agents in no team were never answered', null, $noTeam['waiting'], 'leads.waiting', $noTeam['filters']);

        $board = collect($page['top']['rows'])->keyBy(fn ($r) => $r['agent']['id']);
        foreach (array_slice($page['top']['tops']['leads'], 0, self::AGENTS) as $i => $agentId) {
            $row = $board->get($agentId);
            if ($row !== null && $row['leads'] > 0) {
                $id = 'agent_'.($i + 1).'_leads';
                $facts[$id] = self::cell("Leads to agent {$row['agent']['name']}", "{{$id}} leads went to {{$id}.name}", (string) $row['agent']['name'], $row['leads'], 'leads.all', $row['filters']);
            }
        }

        return [
            'meta' => [
                'range' => ['start' => $page['range']['start'], 'end' => $page['range']['end']],
                'previous' => $page['range']['previous'],
            ],
            'facts' => $facts,
        ];
    }

    /** What the facts say, independent of when they were read — equal facts, equal hash. */
    public static function hash(array $facts): string
    {
        return hash('sha256', json_encode($facts['facts']));
    }

    /**
     * The facts measured over the range only — what a "these numbers changed"
     * check compares. "As of now" figures (waiting, dormant) move by the
     * minute, and an analysis isn't out of date because of them.
     */
    public static function stableHash(array $facts): string
    {
        return hash('sha256', json_encode(array_filter($facts['facts'], fn ($f) => $f['as_of'] === 'range')));
    }

    private function region(array &$facts, string $id, array $row): void
    {
        $name = (string) $row['office'];
        $facts["{$id}_agents"] = self::cell("Roster agents at {$name} offices", "{{$id}_agents} agents at {{$id}_agents.name} offices", $name, $row['headcount'], 'agents.headcount', $row['filters']);
        $facts["{$id}_working"] = self::cell("Agents working at {$name} offices", "{{$id}_working} agents working at {{$id}_working.name} offices", $name, $row['active'], 'agents.active', $row['filters']);
        $facts["{$id}_leads"] = self::cell("Leads to agents at {$name} offices", "{{$id}_leads} leads to agents at {{$id}_leads.name} offices", $name, $row['leads'], 'leads.all', $row['filters']);
        $facts["{$id}_answered_rate"] = self::percent("Share of chat leads answered at {$name} offices", "{{$id}_answered_rate} of chat leads answered at {$name} offices", $row['answered_rate'], 'now', 'up') + ['name' => $name];
        $facts["{$id}_waiting"] = self::cell("Never-answered leads at {$name} offices", "{{$id}_waiting} leads never answered at {{$id}_waiting.name} offices", $name, $row['waiting'], 'leads.waiting', $row['filters']);
    }

    private function team(array &$facts, string $id, array $row): void
    {
        $name = (string) $row['team']['name'];
        $facts["{$id}_working"] = self::cell("Agents working in team {$name} (of {$row['members']})", "{{$id}_working} agents working in team {{$id}_working.name}", $name, $row['active'], 'agents.active', $row['filters']);
        $facts["{$id}_members"] = self::cell("Agents in team {$name}", "{{$id}_members} agents in team {{$id}_members.name}", $name, $row['members'], 'agents.headcount', $row['filters']);
        $facts["{$id}_rate"] = self::percent("Share of team {$name}'s agents working", "{{$id}_rate} of team {$name}'s agents working", $row['active_rate'], 'range', 'up') + ['name' => $name];
        $facts["{$id}_waiting"] = self::cell("Never-answered leads of team {$name}", "{{$id}_waiting} leads never answered in team {{$id}_waiting.name}", $name, $row['waiting'], 'leads.waiting', $row['filters']);
    }

    /** A tile as a fact — the same shape fromSummary() gives its tiles. */
    private static function stat(array $stat, string $id): array
    {
        // A range that starts before FH kept this data has no fair comparison.
        $partial = $stat['partial'] || ($stat['previous_partial'] ?? false);
        $ai = MetricRegistry::get($stat['key'])['ai'];

        return [
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
            'filters' => $stat['filters'] ?? [],
        ];
    }

    /** A share in percent — worked out, not a list, so the page shows it without a link. */
    private static function percent(string $label, string $say, ?int $value, string $asOf, string $better): array
    {
        return [
            'label' => $label,
            'say' => $say,
            'value' => $value,
            'previous' => null,
            'change_pct' => null,
            'sample' => null,
            'unit' => 'percent',
            'as_of' => $asOf,
            'partial' => false,
            'metric' => null,
            'filters' => [],
            'better' => $better,
        ];
    }

    private static function rate(int|float|null $part, int|float|null $whole): ?int
    {
        return $part !== null && $whole ? (int) round($part / $whole * 100) : null;
    }

    private static function cell(string $label, string $say, ?string $name, int|float|null $value, string $metric, array $filters): array
    {
        return array_filter([
            'label' => $label,
            'say' => $say,
            'name' => $name,
            'value' => $value,
            'previous' => null,
            'change_pct' => null,
            'sample' => null,
            'unit' => 'count',
            'as_of' => MetricRegistry::get($metric)['as_of'] === 'now' ? 'now' : 'range',
            'partial' => false,
            'metric' => $metric,
            'filters' => $filters,
        ], fn ($v, $k) => $k !== 'name' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }
}
