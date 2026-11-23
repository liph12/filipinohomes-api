<?php

namespace App\Services\Listing;

use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Facades\DB;

/**
 * Listing Insights → Demand: what people did with the listings — views,
 * inquiries and saves — and how many live listings drew no inquiry at all.
 *
 * Unlike the other sections, the window dates the EVENT (the day an inquiry
 * arrived, a view was counted, a save was made), on any listing in the place
 * and team scope — not the day the listing was added. So this service's
 * listing scope carries no date window of its own.
 *
 *   inquiries   listing chats opened by a client, one per client per listing,
 *               whatever their moderation status: the Executive Report's and
 *               Inquiry Analytics' rule (ReportQueries::inquiries()).
 *   views       `listing_view_days` (one view per device per listing per
 *               Manila day). All time has no daily history to add up, so it
 *               reads the running `listings.clicks` total instead.
 *   saves       favorites created in the window. Un-saving deletes the row, so
 *               this is "saved in the window and still saved".
 *   no_inquiry  live listings (public and active, added before the window
 *               ended) with no client inquiry inside it.
 *
 * Every figure beside a window carries the period before it (null for all time).
 */
class ListingDemandService extends ListingInsightsService
{
    /** `listing_view_days` has rows only from the day it was introduced. */
    public const VIEWS_SINCE = '2026-10-22';

    private const TOP_LISTINGS = 5;

    /**
     * @param  array<string, mixed>  $filters  date_start, date_end, province_id, city_id, … as configure() reads them
     * @return array<string, mixed>
     */
    public function demand(array $filters, ?array $agentIds = null): array
    {
        $range = ReportRange::lenient($filters['date_start'] ?? null, $filters['date_end'] ?? null);
        unset($filters['date_start'], $filters['date_end']);
        $this->configure($filters, $agentIds);
        $this->dateBasis = 'created';

        $previous = $range !== null && $range->hasPrevious() ? $range->previous() : null;

        // The same two windows as UTC instants (chats, favorites) and as whole days (view days).
        $cur = $range === null ? null : [$range->fromUtc(), $range->toUtc()];
        $prev = $previous === null ? null : [$previous->fromUtc(), $previous->toUtc()];
        $curDays = $range === null ? null : [$range->start, $range->endExclusiveDay()];
        $prevDays = $previous === null ? null : [$previous->start, $previous->endExclusiveDay()];

        // ── Inquiries ──
        if ($cur === null) {
            $inquiries = $this->inquiryJoin($this->slimListingQuery())
                ->selectRaw('COUNT(chats.id) as cur, 0 as prev, COUNT(DISTINCT listings.id) as listings')
                ->first();
        } else {
            [$sel, $bind] = $this->windowed('chats.created_at', '1', $cur, $prev);
            $inquiries = $this->inquiryJoin($this->slimListingQuery())
                ->where('chats.created_at', '>=', $prev[0] ?? $cur[0])
                ->where('chats.created_at', '<', $cur[1])
                ->selectRaw($sel.', COUNT(DISTINCT CASE WHEN chats.created_at >= ? AND chats.created_at < ? THEN listings.id END) as listings', [...$bind, $cur[0], $cur[1]])
                ->first();
        }

        // ── Views ──
        if ($curDays === null) {
            $views = $this->slimListingQuery()
                ->selectRaw('COALESCE(SUM(listings.clicks), 0) as cur, 0 as prev')
                ->first();
        } else {
            [$sel, $bind] = $this->windowed('v.date', 'v.views', $curDays, $prevDays);
            $views = $this->slimListingQuery()
                ->join('listing_view_days as v', 'v.listing_id', '=', 'listings.id')
                ->where('v.date', '>=', $prevDays[0] ?? $curDays[0])
                ->where('v.date', '<', $curDays[1])
                ->selectRaw($sel, $bind)
                ->first();
        }
        // Daily history starts at VIEWS_SINCE: an earlier window is partial, and so is any comparison reaching back before it.
        $viewsPartial = $range === null || $range->openStart || $range->start < self::VIEWS_SINCE;
        $viewsPreviousOk = $previous !== null && $previous->start >= self::VIEWS_SINCE;

        // ── Saves ──
        if ($cur === null) {
            $saves = $this->slimListingQuery()
                ->join('favorites as f', 'f.listing_id', '=', 'listings.id')
                ->selectRaw('COUNT(f.id) as cur, 0 as prev')
                ->first();
        } else {
            [$sel, $bind] = $this->windowed('f.created_at', '1', $cur, $prev);
            $saves = $this->slimListingQuery()
                ->join('favorites as f', 'f.listing_id', '=', 'listings.id')
                ->where('f.created_at', '>=', $prev[0] ?? $cur[0])
                ->where('f.created_at', '<', $cur[1])
                ->selectRaw($sel, $bind)
                ->first();
        }

        // ── Live listings with no inquiry in the window ──
        $without = $this->slimListingQuery()
            ->where('listings.visibility', 'public')
            ->where('properties.status', 'active')
            ->when($range !== null, fn ($q) => $q->where('listings.created_at', '<', $range->toUtc()))
            ->leftJoinSub($this->inquiriesByListing($cur, countRows: false), 'i', 'i.type_id', '=', 'listings.id')
            ->selectRaw('COUNT(listings.id) as base, SUM(CASE WHEN i.type_id IS NULL THEN 1 ELSE 0 END) as without_inquiry')
            ->first();

        return [
            'range' => $this->describeRange($range),
            'views' => [
                'total' => (int) ($views->cur ?? 0),
                'previous' => $viewsPreviousOk ? (int) ($views->prev ?? 0) : null,
                'since' => self::VIEWS_SINCE,
                'partial' => $viewsPartial,
                'source' => $range === null ? 'lifetime' : 'daily',
            ],
            'inquiries' => [
                'total' => (int) ($inquiries->cur ?? 0),
                'previous' => $previous === null ? null : (int) ($inquiries->prev ?? 0),
                'listings' => (int) ($inquiries->listings ?? 0),
            ],
            'saves' => [
                'total' => (int) ($saves->cur ?? 0),
                'previous' => $previous === null ? null : (int) ($saves->prev ?? 0),
            ],
            'no_inquiry' => [
                'base' => (int) ($without->base ?? 0),
                'count' => (int) ($without->without_inquiry ?? 0),
            ],
            'top' => $this->topListings($range, $cur),
        ];
    }

    /**
     * The listings people reached out to or looked at most in the window:
     * inquiries first, then views.
     *
     * @param  array{0: string, 1: string}|null  $cur
     * @return list<array{id: int, name: ?string, slug: ?string, inquiries: int, views: int}>
     */
    private function topListings(?ReportRange $range, ?array $cur): array
    {
        $query = $this->slimListingQuery()
            ->leftJoinSub($this->inquiriesByListing($cur, countRows: true), 'i', 'i.type_id', '=', 'listings.id');

        if ($range === null) {
            // No daily history for all time: the running view total stands in.
            $query->where(fn ($w) => $w->whereNotNull('i.n')->orWhere('listings.clicks', '>', 0))
                ->select('listings.id', 'listings.name', 'listings.slug', DB::raw('COALESCE(i.n, 0) as inquiries'), DB::raw('COALESCE(listings.clicks, 0) as views'));
        } else {
            $days = DB::table('listing_view_days')
                ->where('date', '>=', $range->start)
                ->where('date', '<', $range->endExclusiveDay())
                ->select('listing_id', DB::raw('SUM(views) as n'))
                ->groupBy('listing_id');
            $query->leftJoinSub($days, 'v', 'v.listing_id', '=', 'listings.id')
                ->where(fn ($w) => $w->whereNotNull('i.n')->orWhereNotNull('v.n'))
                ->select('listings.id', 'listings.name', 'listings.slug', DB::raw('COALESCE(i.n, 0) as inquiries'), DB::raw('COALESCE(v.n, 0) as views'));
        }

        return $query
            ->orderByDesc('inquiries')
            ->orderByDesc('views')
            ->orderBy('listings.id')
            ->limit(self::TOP_LISTINGS)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'inquiries' => (int) $r->inquiries,
                'views' => (int) $r->views,
            ])
            ->all();
    }

    /**
     * Client-opened listing chats (inside the window, when there is one) per
     * listing — `type_id` alone, or with the count as `n`.
     *
     * @param  array{0: string, 1: string}|null  $cur
     */
    private function inquiriesByListing(?array $cur, bool $countRows)
    {
        $query = DB::table('chats')
            ->join('users as inq', 'inq.id', '=', 'chats.user_id')
            ->join('roles as inq_role', function ($join) {
                $join->on('inq_role.id', '=', 'inq.role_id')->where('inq_role.name', 'client');
            })
            ->where('chats.type', 'listing')
            ->whereNull('chats.deleted_at')
            ->when($cur !== null, fn ($q) => $q->where('chats.created_at', '>=', $cur[0])->where('chats.created_at', '<', $cur[1]))
            ->groupBy('chats.type_id');

        return $countRows
            ? $query->select('chats.type_id', DB::raw('COUNT(chats.id) as n'))
            : $query->select('chats.type_id');
    }

    /** Join a listing query to its client-opened inquiry chats. */
    private function inquiryJoin($query)
    {
        return $query
            ->join('chats', function ($join) {
                $join->on('chats.type_id', '=', 'listings.id')
                    ->where('chats.type', 'listing')
                    ->whereNull('chats.deleted_at');
            })
            ->join('users as inq', 'inq.id', '=', 'chats.user_id')
            ->join('roles as inq_role', function ($join) {
                $join->on('inq_role.id', '=', 'inq.role_id')->where('inq_role.name', 'client');
            });
    }

    /**
     * `SUM(CASE WHEN col in window THEN $then ELSE 0 END)` for the current and
     * the previous window (aliases `cur` and `prev`), with their bindings. A
     * missing window selects a constant 0, so the caller reads both either way.
     *
     * @param  array{0: string, 1: string}|null  $cur
     * @param  array{0: string, 1: string}|null  $prev
     * @return array{0: string, 1: list<string>}
     */
    private function windowed(string $column, string $then, ?array $cur, ?array $prev): array
    {
        $sql = [];
        $bindings = [];
        foreach (['cur' => $cur, 'prev' => $prev] as $alias => $window) {
            if ($window === null) {
                $sql[] = "0 as {$alias}";

                continue;
            }
            $sql[] = "COALESCE(SUM(CASE WHEN {$column} >= ? AND {$column} < ? THEN {$then} ELSE 0 END), 0) as {$alias}";
            array_push($bindings, $window[0], $window[1]);
        }

        return [implode(', ', $sql), $bindings];
    }
}
