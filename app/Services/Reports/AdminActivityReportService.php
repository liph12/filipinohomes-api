<?php

namespace App\Services\Reports;

use App\Models\Project;
use App\Services\Audience\AudienceGeographyService;
use App\Services\Audience\EngagementOverviewService;
use App\Services\Audience\TrafficSourceService;
use App\Services\Listing\ListingCreatedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gathers the numbers for the boss-facing activity report email, one date
 * window at a time. Every section reuses the SAME definitions the admin
 * dashboards use (Audience Insights / Listing Insights / Inquiry Analytics),
 * so the email never disagrees with the screens:
 *
 *  - audience   : unique visitors PER DAY summed across the window (a device
 *                 counts once per day it visited), new clients, returning
 *                 clients (EngagementOverviewService definitions), plus new
 *                 AGENT accounts (role=agent) registered in the window.
 *  - traffic    : TrafficSourceService::channels() — acquisition channel table
 *                 with per-channel new/returning clients.
 *  - geography  : AudienceGeographyService::breakdown('PH') — top provinces
 *                 and cities, PHILIPPINES ONLY by request.
 *  - listings   : ListingCreatedService::createdTimeline() — listings created
 *                 in the window; beside it, TRANSACTIONS — listings whose
 *                 property moved to sold / rented / leased within the window
 *                 (properties.status + status_change_date, the same fields the
 *                 status-change action writes).
 *  - projects   : projects created in the window (count + names).
 *  - inquiries  : chats of type 'listing' (the live inquiry model) received in
 *                 the window; approvals via conversations.status — 'accepted'
 *                 reviewed within the window, plus the CURRENT 'pending'
 *                 backlog (yet to approve is a live queue, not a window stat).
 */
class AdminActivityReportService
{
    public function __construct(
        private EngagementOverviewService $engagement,
        private TrafficSourceService $traffic,
        private AudienceGeographyService $geography,
        private ListingCreatedService $listingsCreated,
    ) {}

    /**
     * @param  string  $start  'Y-m-d'
     * @param  string  $end  'Y-m-d'
     */
    public function build(string $start, string $end): array
    {
        $audience = $this->engagement->range($start, $end)->size();

        $startDt = $start.' 00:00:00';
        $endDt = $end.' 23:59:59';

        // Unique visitors PER DAY, summed — one device = one count per day it
        // visited (not once per window). Same audience scope as the dashboard
        // (anonymous + clients; agent/admin browsing excluded).
        $dailyUniqueVisitors = (int) DB::query()->fromSub(
            DB::table('visits')
                ->leftJoin('users', 'users.id', '=', 'visits.user_id')
                ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
                ->whereBetween('visits.created_at', [$startDt, $endDt])
                ->where(function ($q) {
                    $q->whereNull('visits.user_id')->orWhere('roles.name', 'client');
                })
                ->whereNotNull('visits.visitor_id')
                ->groupBy(DB::raw('DATE(visits.created_at)'))
                ->select(DB::raw('COUNT(DISTINCT visits.visitor_id) as c')),
            'per_day',
        )->sum('c');

        // New AGENT accounts — new_clients above is role=client only.
        $newAgents = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'agent')
            ->whereBetween('users.created_at', [$startDt, $endDt])
            ->count();

        $traffic = $this->traffic->range($start, $end)->channels();
        $geo = $this->geography->range($start, $end)->breakdown('PH');

        $created = $this->listingsCreated->createdTimeline([
            'date_start' => $start,
            'date_end' => $end,
            'province_id' => null,
            'city_id' => null,
            'island' => null,
            'region' => null,
            'barangay_id' => null,
            'granularity' => 'day',
        ], null);

        // Transactions closed in the window: the status-change action stamps
        // properties.status + status_change_date, so "sold this week" is a
        // date filter, not a guess from updated_at. Joined through listings so
        // only listed properties count.
        $txRow = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->whereIn('properties.status', ['sold', 'rented', 'leased'])
            ->whereBetween('properties.status_change_date', [$start, $endDt])
            ->selectRaw("
                SUM(properties.status = 'sold') as sold,
                SUM(properties.status = 'rented') as rented,
                SUM(properties.status = 'leased') as leased
            ")
            ->first();

        $projects = Project::whereBetween('created_at', [$startDt, $endDt])
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'created_at', 'added_by', 'email']);

        // The live inquiry model — one chats row of type 'listing' is one
        // inquiry (see InquiryInsightsService); approval state lives on its
        // conversations rows.
        $received = DB::table('chats')
            ->where('type', 'listing')
            ->whereBetween('created_at', [$startDt, $endDt])
            ->count();

        $approved = DB::table('conversations')
            ->where('status', 'accepted')
            ->whereBetween('reviewed_at', [$startDt, $endDt])
            ->count();

        // Inquiry response — over the inquiries FORWARDED in this window only:
        // answered = the assigned agent sent a message after the forward
        // (reviewed_at), same join RecomputeAgentResponseMetrics uses;
        // unanswered = forwarded but no agent reply yet.
        $answered = DB::table('conversations as c')
            ->where('c.status', 'accepted')
            ->whereBetween('c.reviewed_at', [$startDt, $endDt])
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages as m')
                    ->whereColumn('m.conversation_id', 'c.id')
                    ->whereColumn('m.user_id', 'c.agent_user_id')
                    ->whereColumn('m.created_at', '>', 'c.reviewed_at')
                    ->whereIn('m.status', ['active', 'updated']);
            })
            ->count();

        $pendingNow = DB::table('conversations')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();

        // Agent birthdays — today's and the next 30 days, from agents.birthdate
        // (the LR backfill). Month-day matching in SQL via a generated list of
        // the 31 'MM-DD' keys, so year-wrap (Dec → Jan) costs nothing; the
        // 01-01 epoch default is junk data, not a birthday.
        $endRef = Carbon::parse($end);
        $mdKeys = [];
        for ($i = 0; $i <= 30; $i++) {
            $mdKeys[] = $endRef->copy()->addDays($i)->format('m-d');
        }
        $bdayRows = DB::table('agents')
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->whereNotNull('agents.birthdate')
            ->where('agents.birthdate', '!=', '1970-01-01')
            ->whereIn(DB::raw("DATE_FORMAT(agents.birthdate, '%m-%d')"), $mdKeys)
            ->get(['users.name', 'agents.birthdate']);

        $birthdaysToday = [];
        $birthdaysUpcoming = [];
        foreach ($bdayRows as $row) {
            $md = Carbon::parse($row->birthdate)->format('m-d');
            $offset = array_search($md, $mdKeys, true);
            if ($offset === false) {
                continue;
            }
            $entry = [
                'name' => (string) $row->name,
                'date' => $endRef->copy()->addDays($offset)->format('M j'),
                'offset' => (int) $offset,
            ];
            if ($offset === 0) {
                $birthdaysToday[] = $entry;
            } else {
                $birthdaysUpcoming[] = $entry;
            }
        }
        usort($birthdaysUpcoming, fn ($a, $b) => $a['offset'] <=> $b['offset'] ?: strcasecmp($a['name'], $b['name']));

        // Cap the upcoming list at 10 — but never cut off TOMORROW: when today
        // + tomorrow alone exceed 10, show everything up to tomorrow instead
        // (and only that far). The blade notes how many more the window holds.
        $upcomingTotal = count($birthdaysUpcoming);
        $tomorrowCount = count(array_filter($birthdaysUpcoming, fn ($b) => $b['offset'] === 1));
        if (count($birthdaysToday) + $tomorrowCount > 10) {
            $birthdaysUpcoming = array_values(array_filter($birthdaysUpcoming, fn ($b) => $b['offset'] === 1));
        } else {
            $birthdaysUpcoming = array_slice($birthdaysUpcoming, 0, 10);
        }

        return [
            'range' => ['start' => $start, 'end' => $end],
            'audience' => [
                'unique_visitors' => $dailyUniqueVisitors,
                'new_clients' => (int) ($audience['new_clients'] ?? 0),
                'returning_clients' => (int) ($audience['returning_clients'] ?? 0),
                'new_agents' => $newAgents,
            ],
            // Every channel, uncut — the channel list is short by nature
            // (organic / social / referral / direct / …).
            'traffic_channels' => $traffic['channels'] ?? [],
            'geo_ph' => [
                // The full top 10 the geography service computes — no trimming.
                'provinces' => $geo['states'] ?? [],
                'cities' => $geo['cities'] ?? [],
            ],
            'listings' => [
                'total' => (int) ($created['total'] ?? 0),
            ],
            'transactions' => [
                'sold' => (int) ($txRow->sold ?? 0),
                'rented' => (int) ($txRow->rented ?? 0),
                'leased' => (int) ($txRow->leased ?? 0),
            ],
            'projects' => [
                'total' => $projects->count(),
                // Names only, capped — the email is a digest, not a table dump.
                // added_by holds the creating agent's email; `email` is the
                // project's contact email — the fallback when added_by is blank.
                'names' => $projects->take(12)->map(fn ($p) => [
                    'name' => (string) $p->name,
                    'agent_email' => (string) ($p->added_by ?: $p->email ?: '—'),
                ])->all(),
            ],
            'inquiries' => [
                'received' => $received,
                'approved' => $approved,
                'pending_now' => $pendingNow,
            ],
            'inquiry_response' => [
                'answered' => $answered,
                'unanswered' => max(0, $approved - $answered),
            ],
            'birthdays' => [
                'today' => $birthdaysToday,
                'upcoming' => $birthdaysUpcoming,
                'upcoming_total' => $upcomingTotal,
            ],
        ];
    }
}
