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
    /**
     * Unique audience devices per channel (anonymous + clients) — channel => count. The light read the
     * admin dashboard's Traffic Source / AI cards need; channels() builds the full report on top of it.
     */
    public function visitorsByChannel(): \Illuminate\Support\Collection
    {
        return $this->audienceVisits()
            ->whereNotNull('visits.visitor_id')
            ->groupBy('visits.channel')
            ->select('visits.channel as channel', DB::raw('COUNT(DISTINCT visits.visitor_id) as c'))
            ->pluck('c', 'channel');
    }

    public function channels(): array
    {
        $visitors = $this->visitorsByChannel();

        // Visits = visitor-days per channel (one visits row per device per channel per day), so a device
        // that came from Google on 5 days is 1 visitor and 5 visits.
        $visits = $this->audienceVisits()
            ->whereNotNull('visits.visitor_id')
            ->groupBy('visits.channel')
            ->select('visits.channel as channel', DB::raw('COUNT(*) as c'))
            ->pluck('c', 'channel');

        // First source IN RANGE per device — the channel of its earliest visit row in the range. Each
        // device lands in exactly one channel, so these add up to the unique-visitor total (the
        // "First source" view); `value` above counts a device in every channel it used.
        $firstInRangeIds = $this->audienceVisits()
            ->whereNotNull('visits.visitor_id')
            ->groupBy('visits.visitor_id')
            ->select('visits.visitor_id', DB::raw('MIN(visits.id) as first_id'));
        $firstInRange = DB::table('visits')
            ->joinSub($firstInRangeIds, 'fr', fn ($j) => $j->on('visits.id', '=', 'fr.first_id'))
            ->groupBy('visits.channel')
            ->select('visits.channel as channel', DB::raw('COUNT(*) as c'))
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
                'visits'    => (int) ($visits[$channel] ?? 0),
                'first'     => (int) ($firstInRange[$channel] ?? 0),
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
                'visits'    => 0,
                'first'     => 0,
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

        // Anonymous = devices with no logged-in visit in the range AND not tied to any account (signup stamps
        // users.visitor_id). The account check also covers visits stored before user_id was recorded.
        $anonymousDevices = DB::query()->fromSub(
            $this->audienceVisits()
                ->whereNotNull('visits.visitor_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users as acct')
                    ->whereColumn('acct.visitor_id', 'visits.visitor_id'))
                ->groupBy('visits.visitor_id')
                ->havingRaw('MAX(visits.user_id) IS NULL')
                ->select('visits.visitor_id'),
            't'
        )->count();

        return [
            'channels'              => $channels,
            'unique_visitors'       => $uniqueDevices,
            'anonymous_visitors'    => $anonymousDevices,
            'multi_source_devices'  => $this->multiSourceDevices(),
            'referrers'             => $this->referrers(),
            'multi_source_overcount' => max(0, $perChannelSum - $uniqueDevices),
        ];
    }

    /** Android package names → readable app names for the referrer list. */
    private const APP_NAMES = [
        'com.linkedin.android'      => 'LinkedIn app',
        'com.google.android.gm'     => 'Gmail app',
        'com.microsoft.office.outlook' => 'Outlook app',
        'com.slack'                 => 'Slack app',
        'org.telegram.messenger'    => 'Telegram app',
        'com.viber.voip'            => 'Viber app',
        'com.whatsapp'              => 'WhatsApp app',
        'com.reddit.frontpage'      => 'Reddit app',
        'com.pinterest'             => 'Pinterest app',
        'jp.naver.line.android'     => 'LINE app',
        'com.discord'               => 'Discord app',
    ];

    /**
     * What's inside the "referral" channel: the site, app or utm tag each referral visit came from,
     * with people (distinct devices) and visits (rows) per name, biggest first. Mirrors
     * VisitController::resolveChannel — a utm_source decided the channel when present, so it names
     * the row; otherwise the referrer host (android-app://<package> for Android apps).
     */
    private function referrers(): array
    {
        $groups = [];
        $this->audienceVisits()
            ->where('visits.channel', 'referral')
            ->select('visits.id', 'visits.visitor_id', 'visits.referrer', 'visits.utm_source')
            ->orderBy('visits.id')
            ->chunk(2000, function ($rows) use (&$groups) {
                foreach ($rows as $row) {
                    [$name, $kind] = $this->referrerName($row->utm_source, $row->referrer);
                    $g = &$groups[$kind . '|' . $name];
                    $g ??= ['name' => $name, 'kind' => $kind, 'visits' => 0, 'ids' => []];
                    $g['visits']++;
                    if ($row->visitor_id) {
                        $g['ids'][$row->visitor_id] = true;
                    }
                    unset($g);
                }
            });

        return collect($groups)
            ->map(fn ($g) => ['name' => $g['name'], 'kind' => $g['kind'], 'people' => count($g['ids']), 'visits' => $g['visits']])
            ->sortBy([['people', 'desc'], ['visits', 'desc']])
            ->values()
            ->take(50)
            ->all();
    }

    /** @return array{0: string, 1: 'site'|'app'|'tag'|'unknown'} */
    private function referrerName(?string $utmSource, ?string $referrer): array
    {
        $tag = strtolower(trim((string) $utmSource));
        if ($tag !== '') {
            return [$tag, 'tag'];
        }

        $ref = trim((string) $referrer);
        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        if ($host === '') {
            return ['Unknown', 'unknown'];
        }
        if (str_starts_with(strtolower($ref), 'android-app://')) {
            return [self::APP_NAMES[$host] ?? $host, 'app'];
        }

        return [preg_replace('/^www\./', '', $host), 'site'];
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
