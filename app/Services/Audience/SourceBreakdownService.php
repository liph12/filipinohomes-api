<?php

namespace App\Services\Audience;

use Illuminate\Support\Facades\DB;

/**
 * Powers the front-end SourceBreakdown card: per-day anonymous-visitor counts
 * split by acquisition channel (the multi-line chart). Returns aligned date
 * labels + one zero-filled series per channel (ordered by total, biggest first).
 */
class SourceBreakdownService extends AudienceInsightsService
{
    public function build(): array
    {
        $rows = DB::table('visits')
            ->whereBetween('created_at', [$this->startDt, $this->endDt])
            ->whereNull('user_id')
            ->groupBy(DB::raw('DATE(created_at)'), 'channel')
            ->select(DB::raw('DATE(created_at) as d'), 'channel', DB::raw('COUNT(DISTINCT visitor_id) as c'))
            ->get();

        // Pivot: byChannel[channel][date] = count, plus per-channel totals.
        $byChannel = [];
        $totals = [];
        foreach ($rows as $r) {
            $ch = (string) $r->channel;
            $byChannel[$ch][$r->d] = (int) $r->c;
            $totals[$ch] = ($totals[$ch] ?? 0) + (int) $r->c;
        }
        arsort($totals);

        // Date axis starts at the first day with data.
        $allKeys = [];
        foreach ($byChannel as $m) {
            $allKeys = array_merge($allKeys, array_keys($m));
        }
        $dates = $this->buildDates($allKeys ? min($allKeys) : null);

        $channels = [];
        foreach (array_keys($totals) as $ch) {
            $channels[] = [
                'channel' => $ch,
                'data'    => array_map(fn ($d) => $byChannel[$ch][$d] ?? 0, $dates),
            ];
        }

        return ['dates' => $dates, 'channels' => $channels];
    }
}
