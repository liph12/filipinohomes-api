<?php

namespace App\Console\Commands;

use App\Services\Listing\ListingFeedSwitch;
use App\Services\Listing\ListingPriceRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: which public listings have a price that cannot be shown as an asking price, why, and what
 * their agents would be asked to fix. Changes nothing. The number an agent typed is never edited.
 *
 *   php artisan listings:price-audit [--csv=path] [--agents=15]
 *
 * With --csv, every suspect listing is written as a row (id, code, agent, category, type, price, reason,
 * suggested figure, whether the agent could simply confirm it).
 */
class PriceAudit extends Command
{
    protected $signature = 'listings:price-audit {--csv= : write every suspect listing to this CSV file} {--agents=15 : how many agents to list}';

    protected $description = 'Report public listings whose price cannot be shown as an asking price, by reason, category and agent (read-only)';

    public function handle(): int
    {
        $approved = ListingFeedSwitch::approvedPrices();

        $rows = DB::table('listings as l')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->leftJoin('property_attributes as pa', 'pa.id', '=', 'p.property_attribute_id')
            ->leftJoin('property_subtypes as ps', 'ps.id', '=', 'pa.property_subtype_id')
            ->leftJoin('property_types as pt', 'pt.id', '=', 'ps.property_type_id')
            ->where('l.visibility', 'public')->whereNull('l.deleted_at')
            ->orderBy('l.id')
            ->get(['l.id', 'l.code', 'l.agent_id', 'l.price', 'l.name', 'c.name as category', 'pt.name as type', 'pa.lot_area']);

        $total = $rows->count();
        $byReason = [];
        $byCategory = [];
        $byAgent = [];
        $suspect = [];

        foreach ($rows as $r) {
            $confirmed = isset($approved[$r->id]) && abs($approved[$r->id] - (float) $r->price) < 0.005;
            $v = ListingPriceRules::classify($r->price, $r->category, $r->type, $r->lot_area, $confirmed);
            $byCategory[$r->category]['listings'] = ($byCategory[$r->category]['listings'] ?? 0) + 1;
            if ($v['status'] === 'ok') {
                continue;
            }

            $key = $v['reason'].($v['confirmable'] ? ' (agent can confirm)' : '');
            $byReason[$key] = ($byReason[$key] ?? 0) + 1;
            $byCategory[$r->category]['suspect'] = ($byCategory[$r->category]['suspect'] ?? 0) + 1;
            $byAgent[$r->agent_id] = ($byAgent[$r->agent_id] ?? 0) + 1;
            $suspect[] = [$r, $v];
        }

        $count = count($suspect);
        $this->info(sprintf(
            'Public listings: %s. Price cannot be shown: %s (%.1f%%). Rules version %d, approved by an agent: %d.',
            number_format($total), number_format($count), $total ? 100 * $count / $total : 0, ListingPriceRules::VERSION, count($approved),
        ));
        $this->newLine();

        ksort($byReason);
        $this->table(['reason', 'listings'], array_map(fn ($k, $n) => [$k, $n], array_keys($byReason), $byReason) ?: [['(none)', 0]]);

        $this->table(
            ['category', 'listings', 'suspect', '%'],
            array_map(
                fn ($name, $c) => [$name, $c['listings'], $c['suspect'] ?? 0, sprintf('%.1f', 100 * ($c['suspect'] ?? 0) / max(1, $c['listings']))],
                array_keys($byCategory), $byCategory,
            ),
        );

        arsort($byAgent);
        $this->line('Agents with the most listings to fix:');
        $this->table(
            ['agent id', 'listings to fix'],
            array_map(fn ($id, $n) => [$id, $n], array_slice(array_keys($byAgent), 0, (int) $this->option('agents')), array_slice($byAgent, 0, (int) $this->option('agents'))),
        );
        $this->line(sprintf('%d agents have at least one listing to fix.', count($byAgent)));

        if ($path = $this->option('csv')) {
            $fh = fopen($path, 'w');
            fputcsv($fh, ['id', 'code', 'agent_id', 'category', 'type', 'price', 'reason', 'suggested', 'agent_can_confirm', 'title']);
            foreach ($suspect as [$r, $v]) {
                fputcsv($fh, [$r->id, $r->code, $r->agent_id, $r->category, $r->type, $r->price, $v['reason'], $v['suggested'], $v['confirmable'] ? 'yes' : 'no', $r->name]);
            }
            fclose($fh);
            $this->info("Wrote {$count} rows to {$path}");
        }

        return self::SUCCESS;
    }
}
