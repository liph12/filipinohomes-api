<?php

namespace App\Console\Commands;

use App\Services\Reports\Executive\ExecutiveReportService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * How long the Executive Report summary takes to build, part by part, on this
 * server's data — run where there is no web request limit (the production
 * host cuts a request at ~20 seconds). Builds fresh, never from the cache, and
 * writes nothing (the cache included).
 *
 *   php artisan reports:executive-profile                  # the last 30 days
 *   php artisan reports:executive-profile 2026-01-01 2026-11-11
 */
class ProfileExecutiveReport extends Command
{
    protected $signature = 'reports:executive-profile
                            {start? : First day, Y-m-d (Manila); default 29 days before the end}
                            {end? : Last day, Y-m-d (Manila); default today}';

    protected $description = 'Time each part of the Executive Report summary build (no cache, nothing written).';

    public function handle(ExecutiveReportService $report): int
    {
        $end = (string) ($this->argument('end') ?? ReportRange::today());
        try {
            $start = (string) ($this->argument('start') ?? Carbon::createFromFormat('!Y-m-d', $end, ReportRange::TZ)->subDays(29)->format('Y-m-d'));
            $range = ReportRange::resolve($start, $end);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $started = hrtime(true);
        $built = $report->build($range, Carbon::now());
        $total = (int) round((hrtime(true) - $started) / 1e6);

        $timings = $built['timings_ms'];
        arsort($timings);
        $this->table(['Part', 'ms', 'Share'], array_map(
            fn ($part, $ms) => [$part, number_format($ms), $total > 0 ? round($ms / $total * 100).'%' : '—'],
            array_keys($timings),
            $timings,
        ));
        $this->line(sprintf('%s → %s: %s ms in all.', $range->start, $range->end, number_format($total)));
        if ($total > 15000) {
            $this->warn('Over 15 s — close to the ~20 s web request limit; the slowest part above is the one to look at.');
        }

        return self::SUCCESS;
    }
}
