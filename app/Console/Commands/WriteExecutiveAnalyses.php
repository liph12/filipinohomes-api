<?php

namespace App\Console\Commands;

use App\Services\Reports\Executive\Analysis\ExecutiveAnalysisService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Writes the AI Report's analyses ahead of time, so the hub and the Agents
 * page open with them ready instead of an admin waiting for one: each page
 * for "Last 30 days" and "This month" — the page's own presets, so the dates
 * match exactly. At most four calls a night; a range already written today,
 * or whose numbers haven't changed, costs nothing. Scheduled nightly.
 */
class WriteExecutiveAnalyses extends Command
{
    protected $signature = 'reports:write-analyses';

    protected $description = 'Write the AI Report analyses for "Last 30 days" and "This month" so the pages open with them ready.';

    public function handle(ExecutiveAnalysisService $ai): int
    {
        if (! $ai->available()) {
            Log::warning('openai.executive_report.nightly', ['status' => 'unavailable', 'reason' => 'OPENAI_API_KEY is not set']);
            $this->warn('OPENAI_API_KEY is not set — nothing written.');

            return self::SUCCESS;
        }

        $now = Carbon::now();
        $today = ReportRange::today($now);
        $day = Carbon::createFromFormat('!Y-m-d', $today, ReportRange::TZ);
        $ranges = [
            'Last 30 days' => ReportRange::resolve($day->copy()->subDays(29)->format('Y-m-d'), $today, $now),
            'This month' => ReportRange::resolve($day->copy()->startOfMonth()->format('Y-m-d'), $today, $now),
        ];

        foreach (ExecutiveAnalysisService::SCOPES as $scope) {
            foreach ($ranges as $label => $range) {
                $status = $ai->writeNightly($range, $now, $scope);
                $this->line(sprintf('%-8s %-12s %s → %s: %s', $scope, $label, $range->start, $range->end, $status));
            }
        }

        return self::SUCCESS;
    }
}
