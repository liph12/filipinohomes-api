<?php

namespace App\Console\Commands;

use App\Services\Reports\Executive\AgentReportService;
use App\Services\Reports\Executive\ExecutiveReportService;
use App\Services\Reports\Executive\ReportRange;
use App\Support\OfficeRegionMap;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * How long an AI Report page takes to build, part by part, on this server's
 * data — the summary (behind the hub and the topic pages) or the Agents page.
 * Run it where there is no web request limit (the production host cuts a
 * request at ~20 seconds). Builds fresh: the cached page is never read or
 * written (the place-name map caches itself, as on any page load).
 *
 *   php artisan reports:executive-profile                  # the last 30 days
 *   php artisan reports:executive-profile 2026-01-01 2026-11-11
 *   php artisan reports:executive-profile --page=agents --office=cebu
 */
class ProfileExecutiveReport extends Command
{
    protected $signature = 'reports:executive-profile
                            {start? : First day, Y-m-d (Manila); default 29 days before the end}
                            {end? : Last day, Y-m-d (Manila); default today}
                            {--page=summary : summary (the hub and topic pages) or agents}
                            {--office= : With --page=agents: one office region, e.g. cebu}';

    protected $description = 'Time each part of an AI Report page build (fresh; the cached page is never read or written).';

    public function handle(ExecutiveReportService $report, AgentReportService $agents): int
    {
        $page = (string) $this->option('page');
        if (! in_array($page, ['summary', 'agents'], true)) {
            $this->error('--page is summary or agents.');

            return self::FAILURE;
        }
        $office = $this->option('office') ? (string) $this->option('office') : null;
        if ($office !== null && ! in_array($office, [...OfficeRegionMap::REGIONS, 'unassigned'], true)) {
            $this->error('Unknown office region. One of: '.implode(', ', OfficeRegionMap::REGIONS).', unassigned.');

            return self::FAILURE;
        }

        $end = (string) ($this->argument('end') ?? ReportRange::today());
        try {
            $start = (string) ($this->argument('start') ?? Carbon::createFromFormat('!Y-m-d', $end, ReportRange::TZ)->subDays(29)->format('Y-m-d'));
            $range = ReportRange::resolve($start, $end);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $started = hrtime(true);
        $built = $page === 'agents' ? $agents->build($range, Carbon::now(), $office) : $report->build($range, Carbon::now());
        $total = (int) round((hrtime(true) - $started) / 1e6);

        $timings = $built['timings_ms'];
        arsort($timings);
        $this->table(['Part', 'ms', 'Share'], array_map(
            fn ($part, $ms) => [$part, number_format($ms), $total > 0 ? round($ms / $total * 100).'%' : '—'],
            array_keys($timings),
            $timings,
        ));
        $this->line(sprintf('%s%s, %s → %s: %s ms in all.', $page, $office ? " ({$office})" : '', $range->start, $range->end, number_format($total)));
        if ($total > 15000) {
            $this->warn('Over 15 s — close to the ~20 s web request limit; the slowest part above is the one to look at.');
        }

        return self::SUCCESS;
    }
}
