<?php

namespace App\Services\Audience;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared SQL building blocks for the admin "Audience Insights" services
 * (size, acquisition, trend, geography). Counterpart to the Listing Insights
 * services. Audience is admin-only (no team-leader agent scoping); the only
 * per-request scope is the date window, stored on the instance via range() and
 * applied centrally so every sub-query of a single call stays consistent.
 *
 * "Audience" = anonymous visitors + client accounts; agent/admin browsing is
 * excluded everywhere (see audienceVisits()).
 */
abstract class AudienceInsightsService
{
    /** Raw 'Y-m-d' range bounds, used for the trend date axis. */
    protected string $start;
    protected string $end;

    /** Datetime bounds ('Y-m-d H:i:s') applied to created_at filters. */
    protected string $startDt;
    protected string $endDt;

    /**
     * Set the per-request date window. Returns $this so callers can chain into
     * the build method, e.g. $service->range($start, $end)->build().
     */
    public function range(string $start, string $end): static
    {
        $this->start   = $start;
        $this->end     = $end;
        $this->startDt = $start . ' 00:00:00';
        $this->endDt   = $end . ' 23:59:59';

        return $this;
    }

    /**
     * Earliest activity date across users + visits — the "all-time" start used
     * when no date_start is supplied. Falls back to today if there's no data.
     */
    public function earliestDate(): string
    {
        $dates = array_filter([
            DB::table('users')->min('created_at'),
            DB::table('visits')->min('created_at'),
        ]);

        return empty($dates)
            ? now()->toDateString()
            : Carbon::parse(min($dates))->toDateString();
    }

    /**
     * Visits that count as "audience": anonymous OR made by a client account.
     * Agent/admin browsing is excluded. Used for every Visitors count so the
     * number is consistent across the page.
     */
    protected function audienceVisits()
    {
        return DB::table('visits')
            ->leftJoin('users', 'users.id', '=', 'visits.user_id')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->whereBetween('visits.created_at', [$this->startDt, $this->endDt])
            ->where(function ($q) {
                $q->whereNull('visits.user_id')->orWhere('roles.name', 'client');
            });
    }

    /** Clients are scoped via roles.name = 'client'. */
    protected function clientsBase()
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client');
    }

    /**
     * Build the daily date axis for a trend chart. Starts at the first date that
     * actually has data (so all-time charts begin where data exists rather than
     * showing empty leading space or dropping older data), ends at the requested
     * end, with a safety cap of ~1500 most-recent days. Empty when no data.
     *
     * @param string|null $minData earliest 'Y-m-d' present in the series
     */
    protected function buildDates(?string $minData): array
    {
        if ($minData === null) {
            return [];
        }
        $startC = Carbon::parse($this->start);
        $minC   = Carbon::parse($minData);
        if ($minC->gt($startC)) {
            $startC = $minC; // begin where the data begins
        }
        $endC = Carbon::parse($this->end);
        if ($startC->gt($endC)) {
            return [];
        }
        $floor = (clone $endC)->subDays(1499);
        if ($startC->lt($floor)) {
            $startC = $floor;
        }

        $dates = [];
        $cursor = $startC->copy();
        while ($cursor->lte($endC)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}
