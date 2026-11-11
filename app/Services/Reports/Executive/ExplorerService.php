<?php

namespace App\Services\Reports\Executive;

use App\Services\Reports\Executive\Sections\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The Market explorer — the branching boxes the boss asked for ("mo bahig"):
 * property type → town → project → the units themselves, optionally inside
 * one province. "Condos sold in Cebu these two months" is Province Cebu →
 * Condominium; "which team sells Mantra best" is … → Mantra's "Who sells here".
 *
 * Each level is one ReportContext narrowed to the path, folded by the next
 * dimension, so every box value is a drill of its metric with the box's
 * filters (the path's plus its own). Levels:
 *   type     — no `type` yet: a box per property type;
 *   city     — a box per town (`type` = a type id, or `all`);
 *   project  — a box per project in the town (0 = not in a project);
 *   listings — the units: listings live now or with a deal, a listing added or
 *              an inquiry in the dates, with area, ₱/sqm and the map pin.
 * Every level also says who sells there: the top agents and teams by deals.
 */
final class ExplorerService
{
    public const LEAF_LIMIT = 200;

    private const TOP = 10;

    /** Box value => metric. */
    public const VALUES = [
        'live' => 'listings.live',
        'added' => 'listings.added',
        'deals' => 'deals.total',
        'sold' => 'deals.sold',
        'rented' => 'deals.rented',
        'inquiries' => 'inquiries.received',
        'ppsqm_sale' => 'listings.ppsqm_sale',
        'ppsqm_rent' => 'listings.ppsqm_rent',
    ];

    private const DIM_FILTER = ['type' => 'type_id', 'city' => 'city_id', 'project' => 'project_id'];

    public function __construct(
        private readonly ExecutiveReportService $report,
        private readonly ReportQueries $q,
        private readonly Geography $geo,
        private readonly RowHydrator $rows,
    ) {}

    /**
     * @param  array{type?: 'all'|int, province_id?: int, city_id?: int, project_id?: int}  $path
     */
    public function explore(ReportRange $range, Carbon $now, array $path): array
    {
        ksort($path);
        $key = 'exec-report:v1:explorer:'.$range->key().':'.sha1(json_encode($path));

        return Cache::remember($key, min($range->ttl($now), 1800), fn () => $this->build($range, $now, $path));
    }

    public function build(ReportRange $range, Carbon $now, array $path): array
    {
        $level = match (true) {
            ! array_key_exists('type', $path) => 'type',
            ! array_key_exists('city_id', $path) => 'city',
            ! array_key_exists('project_id', $path) => 'project',
            default => 'listings',
        };
        $filters = $this->filtersOf($path);
        $c = $this->report->context($range, $now, $filters);

        $out = [
            'level' => $level,
            'range' => $range->toArray(),
            'path' => $this->crumbs($c, $path),
            'filters' => (object) $filters,
            'columns' => [
                Table::col('live', 'Live now', 'number', 'listings.live'),
                Table::col('added', 'Added', 'number', 'listings.added'),
                Table::col('deals', 'Deals', 'number', 'deals.total'),
                Table::col('sold', 'Sold', 'number', 'deals.sold'),
                Table::col('rented', 'Rented', 'number', 'deals.rented'),
                Table::col('inquiries', 'Inquiries', 'number', 'inquiries.received'),
                Table::col('ppsqm_sale', 'Median ₱/sqm (sale)', 'ppsqm_median', 'listings.ppsqm_sale'),
                Table::col('ppsqm_rent', 'Median ₱/sqm (rent, a month)', 'ppsqm_median', 'listings.ppsqm_rent'),
            ],
            'totals' => ['values' => $this->tally($c, fn () => 0)[0] ?? $this->zero(), 'filters' => (object) $filters],
            'provinces' => $this->provinces(),
            'top_sellers' => $this->sellers($c, $filters),
            'top_teams' => $this->teamsTable($c, $filters),
        ];

        if ($level === 'listings') {
            $out['listings'] = $this->units($c, $filters);
            $out['compare'] = $this->townMedians($range, $now, $filters);
        } else {
            $out['boxes'] = $this->boxes($c, $level, $filters);
        }

        return $out;
    }

    private function filtersOf(array $path): array
    {
        $filters = [];
        if (isset($path['type']) && $path['type'] !== 'all') {
            $filters['type_id'] = (int) $path['type'];
        }
        foreach (['province_id', 'project_id'] as $key) {
            if (array_key_exists($key, $path)) {
                $filters[$key] = (int) $path[$key];
            }
        }
        if (array_key_exists('city_id', $path)) {
            $filters['city_id'] = (int) $path['city_id'] === 0 ? 0 : ($this->geo->cityGroup((int) $path['city_id']) ?? (int) $path['city_id']);
        }
        ksort($filters);

        return $filters;
    }

    // ── Boxes ───────────────────────────────────────────────────────────────

    private function boxes(ReportContext $c, string $level, array $filters): array
    {
        $keyOf = match ($level) {
            'type' => fn ($r) => (int) ($r->type_id ?? 0),
            'city' => fn ($r) => $r->city_id === null ? 0 : ($this->geo->cityGroup((int) $r->city_id) ?? 0),
            'project' => fn ($r) => (int) ($r->project_id ?? 0),
        };
        $tally = $this->tally($c, $keyOf);
        $typeNames = $c->typeNames();
        $projectNames = $level === 'project' ? $c->projectNames(array_keys($tally)) : [];

        $boxes = [];
        foreach ($tally as $key => $values) {
            $boxes[] = [
                'key' => $key,
                'label' => match ($level) {
                    'type' => $typeNames[$key] ?? 'Unspecified',
                    'city' => $key === 0 ? 'No town' : ($this->geo->cityName($key) ?? "City #{$key}"),
                    'project' => $key === 0 ? 'Not in a project' : ($projectNames[$key] ?? "Project #{$key}"),
                },
                'sublabel' => $level === 'city' && $key !== 0 ? $this->geo->provinceName($this->geo->cityProvince($key)) : null,
                'values' => $values,
                'filters' => $filters + [self::DIM_FILTER[$level] => $key],
            ];
        }
        usort($boxes, fn ($a, $b) => [$b['values']['deals'], $b['values']['live'], $b['values']['inquiries'], $b['values']['added'], $a['label']]
            <=> [$a['values']['deals'], $a['values']['live'], $a['values']['inquiries'], $a['values']['added'], $b['label']]);

        return $boxes;
    }

    /** @return array<int|string, array> box key => values (zero-filled) */
    private function tally(ReportContext $c, callable $keyOf): array
    {
        $out = [];
        $count = function (iterable $rows, string $value, ?callable $when = null) use (&$out, $keyOf): void {
            foreach ($rows as $row) {
                if ($when === null || $when($row)) {
                    $key = $keyOf($row);
                    $out[$key][$value] = ($out[$key][$value] ?? 0) + 1;
                }
            }
        };
        $count($c->liveListings(), 'live');
        $count($c->listingsAdded(), 'added');
        $count($c->deals(), 'deals');
        $count($c->deals(), 'sold', fn ($r) => strtolower((string) $r->status) === 'sold');
        $count($c->deals(), 'rented', fn ($r) => strtolower((string) $r->status) === 'rented');
        $count($c->inquiries(), 'inquiries');

        foreach (['ppsqm_sale' => 'listings.ppsqm_sale', 'ppsqm_rent' => 'listings.ppsqm_rent'] as $value => $metric) {
            $samples = [];
            foreach ($c->priced($metric) as $row) {
                $samples[$keyOf($row)][] = $row->ppsqm;
            }
            foreach ($samples as $key => $values) {
                $out[$key][$value] = Table::median(PricePerSqm::median($values), count($values));
            }
        }

        return array_map(fn ($values) => $values + $this->zero(), $out);
    }

    private function zero(): array
    {
        $zero = [];
        foreach (array_keys(self::VALUES) as $value) {
            $zero[$value] = str_starts_with($value, 'ppsqm') ? Table::median(null, 0) : 0;
        }

        return $zero;
    }

    // ── Who sells here ──────────────────────────────────────────────────────

    /** Per-agent measures in the slice: deals, sold, listings added, inquiries. */
    private function measures(ReportContext $c): array
    {
        return [
            'deals' => ReportContext::countBy($c->deals(), 'agent_id'),
            'sold' => ReportContext::countBy($c->deals()->filter(fn ($r) => strtolower((string) $r->status) === 'sold'), 'agent_id'),
            'added' => ReportContext::countBy($c->listingsAdded(), 'agent_id'),
            'inquiries' => ReportContext::countBy($c->inquiries(), 'agent_id'),
        ];
    }

    /** Ids ranked by deals, then sold, listings added, inquiries — ties by id. */
    private static function rank(array $ids, array $measures): array
    {
        usort($ids, function ($a, $b) use ($measures) {
            $score = fn ($id) => array_map(fn ($m) => $m[$id] ?? 0, array_values($measures));

            return [$score($b), $a] <=> [$score($a), $b];
        });

        return array_slice($ids, 0, self::TOP);
    }

    private static function valueColumns(): array
    {
        return [
            Table::col('deals', 'Deals', 'number', 'deals.total'),
            Table::col('sold', 'Sold', 'number', 'deals.sold'),
            Table::col('added', 'Listings added', 'number', 'listings.added'),
            Table::col('inquiries', 'Inquiries', 'number', 'inquiries.received'),
        ];
    }

    private function sellers(ReportContext $c, array $filters): array
    {
        $measures = $this->measures($c);
        $agents = $c->agentRoleIds();
        $ids = [];
        foreach ($measures as $counts) {
            foreach (array_keys($counts) as $id) {
                if ($id !== '' && isset($agents[(int) $id])) {
                    $ids[(int) $id] = true;
                }
            }
        }
        $top = self::rank(array_keys($ids), $measures);
        $people = collect($this->rows->agents($top))->keyBy(fn ($row) => $row['agent']['id']);

        $rows = [];
        foreach ($top as $id) {
            if (($person = $people->get($id)) === null) {
                continue;
            }
            $row = ['agent' => $person['agent']];
            foreach ($measures as $name => $counts) {
                $row[$name] = $counts[$id] ?? 0;
            }
            $rows[] = $row + ['filters' => $filters + ['agent_id' => $id]];
        }

        return ['columns' => [Table::col('agent', 'Agent', 'agent'), ...self::valueColumns()], 'rows' => $rows];
    }

    private function teamsTable(ReportContext $c, array $filters): array
    {
        $measures = array_map(fn ($counts) => array_diff_key($c->byTeam($counts), [0 => true]), $this->measures($c));
        $ids = array_keys(array_replace(...array_values($measures)) ?: []);
        $top = self::rank($ids, $measures);

        $rows = [];
        foreach ($top as $id) {
            $row = ['team' => ['id' => $id, 'name' => $c->teams()->name($id)]];
            foreach ($measures as $name => $counts) {
                $row[$name] = $counts[$id] ?? 0;
            }
            $rows[] = $row + ['filters' => $filters + ['team_id' => $id]];
        }

        return ['columns' => [Table::col('team', 'Team', 'team'), ...self::valueColumns()], 'rows' => $rows];
    }

    // ── The units ───────────────────────────────────────────────────────────

    /**
     * The listings of the slice: live now, or with a deal, a listing added or
     * an inquiry in the dates. Deals first, then live ones, then by inquiries.
     */
    private function units(ReportContext $c, array $filters): array
    {
        $inquiries = ReportContext::countBy($c->inquiries(), 'listing_id');
        $dealt = array_fill_keys($c->deals()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $live = array_fill_keys($c->liveListings()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
        $ids = array_keys($dealt + $live + array_fill_keys($c->listingsAdded()->pluck('id')->map(fn ($id) => (int) $id)->all(), true)
            + array_fill_keys(array_map('intval', array_keys(array_diff_key($inquiries, ['' => true]))), true));

        usort($ids, fn ($a, $b) => [isset($dealt[$b]), isset($live[$b]), $inquiries[$b] ?? 0, $b] <=> [isset($dealt[$a]), isset($live[$a]), $inquiries[$a] ?? 0, $a]);
        $page = array_slice($ids, 0, self::LEAF_LIMIT);
        $details = $this->q->listingDetails($page);

        $rows = [];
        foreach ($page as $id) {
            $d = $details->get($id);
            if ($d === null) {
                continue;
            }
            $area = PricePerSqm::area($d->type, $d->floor_area, $d->lot_area);
            $pin = self::pin($d->geo_coordinates);
            $rows[] = [
                'listing' => $this->rows->listingCell($d->id, $d->name, $d->slug, $d->code, $d->featured_photo),
                'category' => $d->category,
                'type' => $d->type ?? 'Unspecified',
                'status' => $d->status,
                'price' => $d->price !== null ? (float) $d->price : null,
                'area' => $area > 0 ? $area : null,
                'area_basis' => strcasecmp(trim((string) $d->type), 'Land') === 0 ? 'lot' : 'floor',
                'ppsqm' => $d->price !== null ? PricePerSqm::perSqm((float) $d->price, $d->type, $d->floor_area, $d->lot_area) : null,
                'deal_date' => isset($dealt[$id]) && $d->status_change_date ? substr((string) $d->status_change_date, 0, 10) : null,
                'inquiries' => $inquiries[$id] ?? 0,
                'agent' => $this->rows->agentCell($d->agent_id, $d->agent_first_name, $d->agent_last_name, $d->agent_user_name, $d->agent_avatar, $d->agent_user_avatar),
                'pin' => $pin,
                'map_url' => $pin ? 'https://www.google.com/maps/search/?api=1&query='.$pin['lat'].','.$pin['lng'] : null,
                'filters' => $filters + ['listing_id' => $id],
            ];
        }

        return [
            'columns' => [
                Table::col('listing', 'Listing', 'listing'),
                Table::col('status', 'Status', 'status'),
                // ₱/sqm below is a sale price per sqm for one row and a monthly rent for the next.
                Table::col('category', 'Sale / rent', 'text'),
                Table::col('price', 'Asking price', 'money'),
                Table::col('area', 'Area', 'sqm'),
                Table::col('ppsqm', '₱/sqm', 'ppsqm'),
                Table::col('deal_date', 'Deal date', 'date'),
                Table::col('inquiries', 'Inquiries', 'number', 'inquiries.received'),
                Table::col('agent', 'Agent', 'agent'),
                Table::col('map_url', 'Map', 'map'),
            ],
            'rows' => $rows,
            'more' => max(0, count($ids) - self::LEAF_LIMIT),
        ];
    }

    /** The project's medians sit in `totals`; these are its whole town's, for comparison. */
    private function townMedians(ReportRange $range, Carbon $now, array $filters): array
    {
        $town = array_diff_key($filters, ['project_id' => true]);
        $c = $this->report->context($range, $now, $town);
        $out = ['filters' => (object) $town];
        foreach (['ppsqm_sale' => 'listings.ppsqm_sale', 'ppsqm_rent' => 'listings.ppsqm_rent'] as $value => $metric) {
            $values = $c->priced($metric)->pluck('ppsqm')->all();
            $out[$value] = Table::median(PricePerSqm::median($values), count($values));
        }

        return $out;
    }

    /** A map pin from `geo_coordinates` ({"lat":…,"lng":…}), or null outside the Philippines. */
    private static function pin($raw): ?array
    {
        $geo = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($geo) || ! is_numeric($geo['lat'] ?? null) || ! is_numeric($geo['lng'] ?? null)) {
            return null;
        }
        $lat = (float) $geo['lat'];
        $lng = (float) $geo['lng'];

        // The same Philippines box as PropertyGeoResolver — a 0,0 or swapped pin isn't a place.
        return $lat < 4 || $lat > 21 || $lng < 116 || $lng > 127 ? null : ['lat' => $lat, 'lng' => $lng];
    }

    // ── Labels ──────────────────────────────────────────────────────────────

    private function crumbs(ReportContext $c, array $path): array
    {
        $crumbs = [];
        if (array_key_exists('province_id', $path)) {
            $crumbs[] = ['level' => 'province', 'id' => (int) $path['province_id'], 'label' => $this->geo->provinceName((int) $path['province_id']) ?? 'No province'];
        }
        if (array_key_exists('type', $path)) {
            $crumbs[] = ['level' => 'type', 'id' => $path['type'], 'label' => $path['type'] === 'all' ? 'All types' : ($c->typeNames()[(int) $path['type']] ?? 'Unspecified')];
        }
        if (array_key_exists('city_id', $path)) {
            $town = $this->geo->cityGroup((int) $path['city_id']);
            $crumbs[] = ['level' => 'city', 'id' => $town ?? 0, 'label' => $town ? ($this->geo->cityName($town) ?? "City #{$town}") : 'No town'];
        }
        if (array_key_exists('project_id', $path)) {
            $id = (int) $path['project_id'];
            $crumbs[] = ['level' => 'project', 'id' => $id, 'label' => $id === 0 ? 'Not in a project' : ($c->projectNames([$id])[$id] ?? "Project #{$id}")];
        }

        return $crumbs;
    }

    /** The province filter's choices: one entry per real province (duplicates folded). */
    private function provinces(): array
    {
        $out = [];
        foreach ($this->geo->provinceNames() as $id => $name) {
            if ($this->geo->canonicalProvince($id) === $id) {
                $out[] = ['id' => $id, 'label' => $name];
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $out;
    }
}
