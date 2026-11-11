<?php

namespace App\Services\Reports\Executive\Sections;

use App\Services\Reports\Executive\ReportContext;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Carbon;

/**
 * Inquiries, listings added and deals over the dates — by day up to 45 days,
 * by week (Monday-first) up to 200, by month beyond. Buckets are Manila days,
 * clipped to the range, and zero-filled; each point carries its own start and
 * end so a click opens the drill for exactly those days. The points of a
 * series add up to the section totals.
 */
final class TrendSection
{
    public const SERIES = [
        'inquiries' => 'inquiries.received',
        'listings' => 'listings.added',
        'deals' => 'deals.total',
    ];

    public function build(ReportContext $c): array
    {
        $range = $c->range;
        $bucket = match (true) {
            $range->days() <= 45 => 'day',
            $range->days() <= 200 => 'week',
            default => 'month',
        };

        $points = [];
        $cursor = $range->fromManila();
        $end = $range->toManila();
        while ($cursor < $end) {
            $next = match ($bucket) {
                'day' => $cursor->copy()->addDay(),
                'week' => $cursor->copy()->startOfWeek(Carbon::MONDAY)->addWeek(),
                'month' => $cursor->copy()->startOfMonth()->addMonth(),
            };
            $next = $next > $end ? $end->copy() : $next;
            $points[$cursor->format('Y-m-d')] = [
                'start' => $cursor->format('Y-m-d'),
                'end' => $next->copy()->subDay()->format('Y-m-d'),
            ] + array_fill_keys(array_keys(self::SERIES), 0);
            $cursor = $next;
        }

        $bucketOf = function (string $day) use ($bucket, $range): string {
            $key = match ($bucket) {
                'day' => $day,
                'week' => Carbon::createFromFormat('!Y-m-d', $day, ReportRange::TZ)->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
                'month' => substr($day, 0, 8).'01',
            };

            return max($key, $range->start);
        };

        $tally = function (iterable $rows, string $series, bool $utc) use (&$points, $bucketOf): void {
            foreach ($rows as $row) {
                $day = $utc ? self::manilaDay((string) $row->at) : substr((string) $row->at, 0, 10);
                $key = $bucketOf($day);
                if (isset($points[$key])) {
                    $points[$key][$series]++;
                }
            }
        };
        $tally($c->inquiries(), 'inquiries', true);
        $tally($c->listingsAdded(), 'listings', true);
        // A deal date is the calendar day the agent typed — already a Manila day.
        $tally($c->deals(), 'deals', false);

        return [
            'bucket' => $bucket,
            'series' => self::SERIES,
            'points' => array_values($points),
        ];
    }

    /** The Manila calendar day of a stored UTC timestamp (Manila keeps UTC+8 all year). */
    private static function manilaDay(string $utc): string
    {
        return gmdate('Y-m-d', strtotime($utc.' UTC') + 8 * 3600);
    }
}
