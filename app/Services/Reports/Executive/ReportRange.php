<?php

namespace App\Services\Reports\Executive;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The date window an Executive Report answers for: Asia/Manila calendar days,
 * both ends included.
 *
 * In SQL it becomes two UTC instants, half-open — from the first day's Manila
 * midnight up to (not including) the midnight after the last day. That is the
 * exact convention the Listings Heatmap binds (ListingHeatmapService::
 * rangeBounds()), so a city's "listings added" here equals the heatmap's for
 * the same dates, and the report's "View on map" lands on matching numbers.
 *
 * Several older services compare timestamps to 'Y-m-d 00:00:00' UTC literals,
 * eight hours off the Manila day. Nothing in the report does: an inquiry at
 * 07:30 on Nov 2 Manila time is a Nov 2 inquiry here, not a Nov 1 one.
 *
 * No CONVERT_TZ in any WHERE clause — the bounds are computed in PHP, so the
 * comparisons stay index-friendly and run unchanged on the SQLite test schema.
 */
final class ReportRange
{
    public const TZ = 'Asia/Manila';

    /** Nothing the report reads predates the 2026 rebuild by much; this just stops nonsense. */
    public const EARLIEST_DAY = '2025-01-01';

    /** A year and a day: "This year" plus the leap day, and no unbounded scans. */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $start,
        public readonly string $end,
        public readonly bool $clamped = false,
    ) {}

    /**
     * Turn a requested range into the one the report answers for.
     *
     * A day after today is clamped to today rather than refused — nothing has
     * happened there yet, so "Nov 1 → Nov 30" asked on Nov 10 IS "Nov 1 →
     * Nov 10" (the heatmap's rule too). The start is then held at or before
     * the end. Both must be strict Y-m-d calendar days.
     */
    public static function resolve(string $start, string $end, ?Carbon $now = null): self
    {
        self::assertDay($start);
        self::assertDay($end);

        if ($end < $start) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }
        if ($start < self::EARLIEST_DAY) {
            throw new InvalidArgumentException('The report starts on '.self::EARLIEST_DAY.'.');
        }

        $today = self::today($now);
        // Zero-padded Y-m-d sorts as a string exactly as it does as a date.
        $clampedEnd = min($end, $today);
        $clampedStart = min($start, $clampedEnd);

        $range = new self($clampedStart, $clampedEnd, $clampedEnd !== $end || $clampedStart !== $start);

        if ($range->days() > self::MAX_DAYS) {
            throw new InvalidArgumentException('A report covers at most '.self::MAX_DAYS.' days.');
        }

        return $range;
    }

    /** Two days that are already valid and in order — no clamping, no span limit. */
    public static function between(string $start, string $end): self
    {
        self::assertDay($start);
        self::assertDay($end);

        return new self($start, $end);
    }

    /** The first day's Manila midnight. */
    public function fromManila(): Carbon
    {
        return self::midnight($this->start);
    }

    /** The Manila midnight AFTER the last day — exclusive. */
    public function toManila(): Carbon
    {
        return self::midnight($this->end)->addDay();
    }

    /** `from` as the UTC literal timestamps are stored in. */
    public function fromUtc(): string
    {
        return $this->fromManila()->utc()->format('Y-m-d H:i:s');
    }

    /** `to` (exclusive) as the UTC literal timestamps are stored in. */
    public function toUtc(): string
    {
        return $this->toManila()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * The day after the last day, for DATE columns (`status_change_date`):
     * `>= start AND < endExclusiveDay()` keeps the same half-open shape.
     */
    public function endExclusiveDay(): string
    {
        return self::midnight($this->end)->addDay()->format('Y-m-d');
    }

    public function days(): int
    {
        return (int) self::midnight($this->start)->diffInDays(self::midnight($this->end)) + 1;
    }

    /** True while the range still includes today (its numbers can still move). */
    public function isCurrent(?Carbon $now = null): bool
    {
        return $this->end >= self::today($now);
    }

    /**
     * The period the deltas compare against — the same rule the Top Listing
     * Creators page uses, so a "This month" delta means the same thing on both:
     *   - a month from its 1st → that span of the month before (the whole month
     *     before when this one is complete);
     *   - a year from Jan 1 → the same span last year;
     *   - several whole months ("Last 2 months": Sep 1 – Oct 31) → the same
     *     number of whole months before (Jul 1 – Aug 31), not the same count of
     *     days (which would start on Jul 2);
     *   - anything else → the same number of days immediately before.
     */
    public function previous(): self
    {
        $s = self::midnight($this->start);
        $e = self::midnight($this->end);

        if ($s->day === 1 && $s->month === $e->month && $s->year === $e->year) {
            $prevLast = $s->copy()->subDay(); // last day of the previous month
            $fullMonth = $e->day === $e->daysInMonth;
            $endDay = $fullMonth ? $prevLast->day : min($e->day, $prevLast->day);

            return new self(
                $prevLast->copy()->startOfMonth()->format('Y-m-d'),
                $prevLast->copy()->day($endDay)->format('Y-m-d'),
            );
        }

        if ($s->day === 1 && $s->month === 1 && $s->year === $e->year) {
            $year = $s->year - 1;
            $monthStart = Carbon::create($year, $e->month, 1, 0, 0, 0, self::TZ);
            $endDay = min($e->day, $monthStart->daysInMonth);

            return new self(sprintf('%04d-01-01', $year), $monthStart->day($endDay)->format('Y-m-d'));
        }

        if ($s->day === 1 && $e->day === $e->daysInMonth) {
            $months = ($e->year - $s->year) * 12 + ($e->month - $s->month) + 1;

            return new self(
                $s->copy()->subMonthsNoOverflow($months)->format('Y-m-d'),
                $s->copy()->subDay()->format('Y-m-d'),
            );
        }

        $days = $this->days();

        return new self(
            $s->copy()->subDays($days)->format('Y-m-d'),
            $s->copy()->subDay()->format('Y-m-d'),
        );
    }

    /**
     * How long a computed report may be served from cache. A range that ended
     * before today can't change except through backdated edits, so it keeps
     * for hours; one still running refreshes in minutes, longer ranges less
     * often (they are dearer to build and a few minutes matter less).
     */
    public function ttl(?Carbon $now = null): int
    {
        if (! $this->isCurrent($now)) {
            return 21600;
        }

        return match (true) {
            $this->days() <= 31 => 600,
            $this->days() <= 92 => 1800,
            default => 3600,
        };
    }

    /** Cache-key fragment. */
    public function key(): string
    {
        return $this->start.':'.$this->end;
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
            'days' => $this->days(),
            'tz' => self::TZ,
            'from' => $this->fromManila()->toIso8601String(),
            'to' => $this->toManila()->toIso8601String(),
            'clamped' => $this->clamped,
        ];
    }

    /** Today's Manila calendar day. */
    public static function today(?Carbon $now = null): string
    {
        return ($now ?? Carbon::now())->copy()->setTimezone(self::TZ)->format('Y-m-d');
    }

    private static function midnight(string $day): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $day, self::TZ);
    }

    private static function assertDay(string $day): void
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $day, self::TZ);
        } catch (\Throwable) {
            $parsed = null; // malformed input throws on some Carbon versions, returns false on others
        }

        // createFromFormat() quietly rolls 2026-02-30 over to March 2.
        if (! $parsed instanceof Carbon || $parsed->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException("Not a Y-m-d calendar day: '{$day}'.");
        }
    }
}
