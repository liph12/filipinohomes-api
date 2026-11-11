<?php

namespace Tests\Unit;

use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Executive Report's date window. Today is frozen at Nov 10, 2026, 2 PM
 * Manila — so "today" is a Manila day that started at 16:00 UTC on Nov 9.
 */
class ExecutiveReportRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 14:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_day_after_today_is_clamped_to_today(): void
    {
        $range = ReportRange::resolve('2026-11-01', '2026-11-30');

        $this->assertSame('2026-11-01', $range->start);
        $this->assertSame('2026-11-10', $range->end);
        $this->assertTrue($range->clamped);
    }

    public function test_a_range_wholly_in_the_future_collapses_onto_today(): void
    {
        $range = ReportRange::resolve('2026-12-01', '2026-12-05');

        $this->assertSame(['2026-11-10', '2026-11-10'], [$range->start, $range->end]);
    }

    public function test_bounds_are_manila_midnights_in_utc_half_open(): void
    {
        $range = ReportRange::resolve('2026-11-02', '2026-11-02');

        $this->assertSame('2026-11-01 16:00:00', $range->fromUtc());
        $this->assertSame('2026-11-02 16:00:00', $range->toUtc());
        $this->assertSame('2026-11-03', $range->endExclusiveDay());
        $this->assertSame(1, $range->days());
    }

    /** [start, end, previous start, previous end] */
    public static function previousCases(): array
    {
        return [
            'month still running → same days of last month' => ['2026-11-01', '2026-11-10', '2026-10-01', '2026-10-10'],
            'a whole month → the whole month before' => ['2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'],
            'March → all of a short February' => ['2026-03-01', '2026-03-31', '2026-02-01', '2026-02-28'],
            'the 31st of a month → clamped into a 30-day one' => ['2026-10-01', '2026-10-30', '2026-09-01', '2026-09-30'],
            'year to date → the same span last year' => ['2026-01-01', '2026-11-10', '2025-01-01', '2025-11-10'],
            'any other window → the same days just before' => ['2026-11-03', '2026-11-09', '2026-10-27', '2026-11-02'],
        ];
    }

    #[DataProvider('previousCases')]
    public function test_the_previous_period_follows_the_top_creators_rules(string $start, string $end, string $prevStart, string $prevEnd): void
    {
        $previous = ReportRange::resolve($start, $end)->previous();

        $this->assertSame([$prevStart, $prevEnd], [$previous->start, $previous->end]);
    }

    public function test_past_ranges_cache_for_hours_and_current_ones_for_minutes(): void
    {
        $this->assertSame(21600, ReportRange::resolve('2026-10-01', '2026-10-31')->ttl());
        $this->assertSame(600, ReportRange::resolve('2026-10-20', '2026-11-10')->ttl());
        $this->assertSame(1800, ReportRange::resolve('2026-09-15', '2026-11-10')->ttl());
        $this->assertSame(3600, ReportRange::resolve('2026-01-01', '2026-11-10')->ttl());
    }

    /** [start, end] */
    public static function rejected(): array
    {
        return [
            'end before start' => ['2026-11-05', '2026-11-01'],
            'not a calendar day' => ['2026-02-30', '2026-03-01'],
            'not a date at all' => ['yesterday', '2026-11-01'],
            'before the report starts' => ['2024-12-31', '2025-01-31'],
            'longer than a year and a day' => ['2025-01-01', '2026-11-10'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_bad_ranges_are_refused(string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportRange::resolve($start, $end);
    }
}
