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
            'two whole months → the two whole months before' => ['2026-09-01', '2026-10-31', '2026-07-01', '2026-08-31'],
            'whole months across a new year' => ['2026-01-01', '2026-02-28', '2025-01-01', '2025-02-28'],
            'whole months starting mid-year → the months just before' => ['2026-03-01', '2026-05-31', '2025-12-01', '2026-02-28'],
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

    // ── lenient(): the Listing Insights window ──────────────────────────────

    public function test_lenient_with_no_usable_day_is_all_time(): void
    {
        $this->assertNull(ReportRange::lenient(null, null));
        $this->assertNull(ReportRange::lenient('', ''));
        $this->assertNull(ReportRange::lenient('garbage', '2026-02-30'), 'a mangled day is ignored, not refused');
    }

    public function test_lenient_fills_in_the_side_that_is_missing(): void
    {
        $upToToday = ReportRange::lenient('2026-11-01', null);
        $this->assertSame(['2026-11-01', '2026-11-10'], [$upToToday->start, $upToToday->end]);
        $this->assertFalse($upToToday->openStart);
        $this->assertTrue($upToToday->hasPrevious());

        $fromTheBeginning = ReportRange::lenient(null, '2026-10-31');
        $this->assertSame('2026-10-31', $fromTheBeginning->end);
        $this->assertSame(ReportRange::OPEN_START_DAY, $fromTheBeginning->start);
        $this->assertTrue($fromTheBeginning->openStart);
        $this->assertFalse($fromTheBeginning->hasPrevious(), 'nothing precedes the beginning');
    }

    public function test_lenient_clamps_a_future_end_and_has_no_span_limit(): void
    {
        // resolve() would refuse this twice over: before the report's first day, and longer than a year.
        $range = ReportRange::lenient('2020-01-01', '2026-12-31');

        $this->assertSame(['2020-01-01', '2026-11-10'], [$range->start, $range->end]);
        $this->assertTrue($range->clamped);
    }

    public function test_lenient_keeps_a_start_after_the_end_so_that_it_holds_nothing(): void
    {
        foreach ([['2026-11-09', '2026-11-01'], ['2026-12-01', '2026-12-05']] as [$start, $end]) {
            $range = ReportRange::lenient($start, $end);

            $this->assertTrue($range->isInverted(), "{$start} → {$end}");
            $this->assertFalse($range->hasPrevious());
            $this->assertGreaterThan($range->toUtc(), $range->fromUtc(), 'no instant falls between from and to');
        }
    }

    public function test_lenient_binds_manila_midnights(): void
    {
        $day = ReportRange::lenient('2026-11-01', '2026-11-01');

        $this->assertSame('2026-10-31 16:00:00', $day->fromUtc());
        $this->assertSame('2026-11-01 16:00:00', $day->toUtc());
        $this->assertSame('2026-11-02', $day->endExclusiveDay());
    }
}
