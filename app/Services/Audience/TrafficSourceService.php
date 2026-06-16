<?php

namespace App\Services\Audience;

use Illuminate\Support\Facades\DB;

/**
 * Powers the front-end TrafficSource card: the acquisition-channel table.
 * Each channel within the range is split into:
 *   - visitors  : unique anonymous devices from that channel (DISTINCT visitor_id)
 *   - new       : clients (role=client) registered within the range whose
 *                 first-touch channel is this one
 *   - returning : clients registered before the range who logged in during it,
 *                 whose first-touch channel is this one (came back)
 * New/returning use FIRST-TOUCH attribution: a client carries the visitor_id of
 * the device they signed up on (users.visitor_id, stamped at signup), and we
 * map that to the channel of their earliest visit — i.e. how they were actually
 * acquired, not where they happened to log in. A channel shows even if it only
 * has client activity (no anonymous visitors).
 */
class TrafficSourceService extends AudienceInsightsService
{
    public function channels(): array
    {
        // Unique audience devices per channel (anonymous + clients).
        $visitors = $this->audienceVisits()
            ->whereNotNull('visits.visitor_id')
            ->groupBy('visits.channel')
            ->select('visits.channel as channel', DB::raw('COUNT(DISTINCT visits.visitor_id) as c'))
            ->pluck('c', 'channel');

        // First-touch channel per visitor_id = the channel of that device's
        // earliest visit row (ids are monotonic with insert time, so MIN(id)
        // is the first visit). Joined to clients via users.visitor_id below.
        $firstVisitIds = DB::table('visits')
            ->whereNotNull('visitor_id')
            ->groupBy('visitor_id')
            ->select('visitor_id', DB::raw('MIN(id) as first_id'));

        $firstTouch = DB::table('visits')
            ->joinSub($firstVisitIds, 'fv', fn ($j) => $j->on('visits.id', '=', 'fv.first_id'))
            ->select('visits.visitor_id', 'visits.channel');

        // Clients (role=client) grouped by their first-touch channel, split by
        // registration date. Mirrors EngagementOverviewService::size() new/
        // returning definitions, just attributed to a channel.
        $clientByChannel = function (bool $registeredInRange) use ($firstTouch) {
            $query = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->joinSub($firstTouch, 'ft', fn ($j) => $j->on('ft.visitor_id', '=', 'users.visitor_id'))
                ->where('roles.name', 'client')
                ->whereNotNull('users.visitor_id');

            if ($registeredInRange) {
                // New = registered within the range.
                $query->whereBetween('users.created_at', [$this->startDt, $this->endDt]);
            } else {
                // Returning = registered before the range AND logged in during it.
                $query->where('users.created_at', '<', $this->startDt)
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('login_logs')
                            ->whereColumn('login_logs.user_id', 'users.id')
                            ->whereBetween('login_logs.logged_in_at', [$this->startDt, $this->endDt]);
                    });
            }

            return $query
                ->groupBy('ft.channel')
                ->select('ft.channel as channel', DB::raw('COUNT(DISTINCT users.id) as c'))
                ->pluck('c', 'channel');
        };

        $newByChannel       = $clientByChannel(true);
        $returningByChannel = $clientByChannel(false);

        // Channel universe = every channel that had any traffic (anonymous or client).
        $channelKeys = collect($visitors->keys())
            ->merge($newByChannel->keys())
            ->merge($returningByChannel->keys())
            ->unique();

        $channels = $channelKeys
            ->map(fn ($channel) => [
                'channel'   => $channel,
                'value'     => (int) ($visitors[$channel] ?? 0),
                'new'       => (int) ($newByChannel[$channel] ?? 0),
                'returning' => (int) ($returningByChannel[$channel] ?? 0),
            ])
            ->sortByDesc('value')
            ->values()
            ->all();

        // "Unknown" bucket — clients we couldn't tie to a channel (no visitor_id,
        // or a visitor_id with no matching visit). Computed as the real total
        // (same definition as EngagementOverview) minus what we could attribute,
        // so the per-channel new/returning always reconcile with the headline
        // counts instead of unattributable clients silently vanishing to 0.
        $unknownNew       = max(0, $this->totalClients(true) - (int) $newByChannel->sum());
        $unknownReturning = max(0, $this->totalClients(false) - (int) $returningByChannel->sum());

        if ($unknownNew > 0 || $unknownReturning > 0) {
            $channels[] = [
                'channel'   => 'unknown',
                'value'     => 0, // no anonymous visitors here — every visit carries a channel
                'new'       => $unknownNew,
                'returning' => $unknownReturning,
            ];
        }

        // Over-count = (per-channel visitor sum) − (unique devices). This is the
        // total EXTRA counting the multi-source devices cause: a device on 3
        // channels adds 2 here. So unique visitors = per-channel sum − overcount.
        $uniqueDevices = $this->audienceVisits()
            ->whereNotNull('visits.visitor_id')
            ->distinct('visits.visitor_id')
            ->count('visits.visitor_id');
        $perChannelSum = (int) $visitors->sum();

        return [
            'channels'              => $channels,
            'multi_source_devices'  => $this->multiSourceDevices(),
            'multi_source_overcount' => max(0, $perChannelSum - $uniqueDevices),
        ];
    }

    /**
     * Devices that reached the site through MORE THAN ONE channel within the
     * range (e.g. Facebook one day, Google another). This is exactly the gap
     * between the per-channel visitor sum and the unique-device total — surfaced
     * as its own number so the overlap is visible instead of implied.
     */
    private function multiSourceDevices(): int
    {
        $sub = $this->audienceVisits()
            ->whereNotNull('visits.visitor_id')
            ->groupBy('visits.visitor_id')
            ->havingRaw('COUNT(DISTINCT visits.channel) > 1')
            ->select('visits.visitor_id');

        return DB::query()->fromSub($sub, 't')->count();
    }

    /**
     * Total clients (role=client) for the range, WITHOUT channel attribution —
     * the same definitions EngagementOverviewService::size() uses for new /
     * returning. Used to size the "Unknown" bucket.
     */
    private function totalClients(bool $registeredInRange): int
    {
        $query = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client');

        if ($registeredInRange) {
            // New = registered within the range.
            return $query
                ->whereBetween('users.created_at', [$this->startDt, $this->endDt])
                ->distinct('users.id')
                ->count('users.id');
        }

        // Returning = registered before the range AND logged in during it.
        return $query
            ->where('users.created_at', '<', $this->startDt)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('login_logs')
                    ->whereColumn('login_logs.user_id', 'users.id')
                    ->whereBetween('login_logs.logged_in_at', [$this->startDt, $this->endDt]);
            })
            ->distinct('users.id')
            ->count('users.id');
    }
}
