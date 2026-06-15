<?php

namespace App\Services\Audience;

use Illuminate\Support\Facades\DB;

/**
 * Powers the front-end TrafficSource card: the acquisition-channel table.
 * Each channel within the range is split into:
 *   - visitors  : unique anonymous devices from that channel (DISTINCT visitor_id)
 *   - new       : clients (role=client) who visited via that channel AND
 *                 registered within the range
 *   - returning : clients who visited via that channel but registered before
 *                 the range start (came back)
 * New/returning are real clients, attributed to a channel via visits that carry
 * their user_id (i.e. they browsed while signed in). A channel shows even if it
 * only has client activity (no anonymous visitors).
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

        // Clients (role=client) per channel, split by registration date.
        $clientByChannel = function (bool $registeredInRange) {
            return DB::table('visits')
                ->join('users', 'users.id', '=', 'visits.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', 'client')
                ->whereBetween('visits.created_at', [$this->startDt, $this->endDt])
                ->when(
                    $registeredInRange,
                    fn ($q) => $q->whereBetween('users.created_at', [$this->startDt, $this->endDt]),
                    fn ($q) => $q->where('users.created_at', '<', $this->startDt),
                )
                ->groupBy('visits.channel')
                ->select('visits.channel as channel', DB::raw('COUNT(DISTINCT visits.user_id) as c'))
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

        return ['channels' => $channels];
    }
}
