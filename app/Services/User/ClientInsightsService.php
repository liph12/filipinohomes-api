<?php

namespace App\Services\User;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The admin Client Insights page, in one payload (clients = role "client"):
 *
 *   - totals    : the headline cards
 *   - journey   : anonymous visitors → signed up → saved a listing → inquired → inquired more than once,
 *                 for the people new in the last 30 days / 90 days / 12 months / all time (journey_ranges)
 *   - growth    : new clients and returning clients per month, last online
 *   - found us  : first-touch channel and device city
 *   - looking   : what they inquire about — category, type → subtype, bedrooms, bathrooms, bed & bath
 *                 combos — with the average price of each, per category
 *   - inquiries : where they inquire and how many each client sends
 *   - replies   : per place (listing / agent profile / agent website / not recorded), did the agent reply
 *
 * An inquiry is a chats row the client started: type 'listing' (type_id = listings.id) or 'agent' (type_id
 * = the agent's user id — "direct"). Direct ones split by chats.source: 'agent_profile' / 'agent_page'
 * (the agent's website); source is only stored from 2026-10-20, older ones are "not recorded".
 *
 * Dates are bucketed in Manila time.
 */
class ClientInsightsService
{
    private const TZ = 'Asia/Manila';

    private const OFFSET = '+08:00';

    private const PLACES = ['listing', 'agent_profile', 'agent_website', 'agent_unrecorded'];

    public function summary(): array
    {
        $clients = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->get(['users.id', 'users.google_id', 'users.visitor_id', 'users.created_at', 'users.last_online_at'])
            ->keyBy('id');
        $ids = $clients->keys()->all();

        $inquiries = $this->inquiryRows($ids);
        // One full pass over visits (~1.5 s on production-sized data) and slow-moving: kept an hour, apart
        // from the page's ten-minute cache.
        $anonymous = Cache::remember('admin:client-insights:anonymous:v3', 3600, fn () => $this->anonymousStats());
        $saved = DB::table('favorites')->whereIn('user_id', $ids)->distinct()->pluck('user_id')->flip();

        // Per client: inquiries in total, and whether any were on a listing / direct to an agent.
        $per = [];
        $byPlace = array_fill_keys(self::PLACES, 0);
        foreach ($inquiries as $r) {
            $per[$r->user_id]['n'] = ($per[$r->user_id]['n'] ?? 0) + 1;
            $per[$r->user_id][$r->type === 'listing' ? 'listing' : 'direct'] = true;
            $byPlace[$r->place]++;
        }

        $now = time();
        $monthStart = now(self::TZ)->startOfMonth()->utc()->getTimestamp();
        $online = ['7 days' => 0, '30 days' => 0, '90 days' => 0, 'Longer' => 0, 'Never' => 0];
        $buckets = ['1' => 0, '2–3' => 0, '4–9' => 0, '10–19' => 0, '20+' => 0];
        $newMonth = $new30 = $active30 = $google = 0;
        foreach ($clients as $c) {
            $seen = $c->last_online_at ? ($now - strtotime($c->last_online_at.' UTC')) / 86400 : null;
            $online[$seen === null ? 'Never' : ($seen <= 7 ? '7 days' : ($seen <= 30 ? '30 days' : ($seen <= 90 ? '90 days' : 'Longer')))]++;
            $active30 += $seen !== null && $seen <= 30 ? 1 : 0;
            $created = $c->created_at ? strtotime($c->created_at.' UTC') : 0;
            $newMonth += $created >= $monthStart ? 1 : 0;
            $new30 += $created >= $now - 30 * 86400 ? 1 : 0;
            $google += $c->google_id ? 1 : 0;
        }
        foreach ($per as $p) {
            $n = $p['n'];
            $buckets[$n === 1 ? '1' : ($n <= 3 ? '2–3' : ($n <= 9 ? '4–9' : ($n <= 19 ? '10–19' : '20+')))]++;
        }

        $inquired = count($per);
        $repeat = count(array_filter($per, fn ($p) => $p['n'] > 1));

        return [
            'totals' => [
                'clients' => $clients->count(),
                'google' => $google,
                'new_30d' => $new30,
                'new_month' => $newMonth,
                'active_30d' => $active30,
                'saved' => $saved->count(),
                'inquired' => $inquired,
                'inquired_listing' => count(array_filter($per, fn ($p) => ! empty($p['listing']))),
                'inquired_direct' => count(array_filter($per, fn ($p) => ! empty($p['direct']))),
                'inquiries' => $inquiries->count(),
                'inquiries_listing' => $byPlace['listing'],
                'inquiries_direct' => $inquiries->count() - $byPlace['listing'],
                'repeat' => $repeat,
            ],
            'journey_ranges' => $this->journeyRanges($clients, $per, $saved, $anonymous),
            'journey_trend' => $this->journeyTrend($inquiries, $ids, $anonymous['monthly']),
            'signups' => $this->monthlySignups(),
            'returning' => $this->monthlyReturning(),
            'inquiries_monthly' => $this->monthlyInquiries($ids),
            'online_buckets' => $online,
            'channels' => $this->firstTouch($clients->pluck('visitor_id')->filter()->unique()->values()->all(), $clients->count()),
            'cities' => $this->cities($ids),
            'looking' => $this->looking($inquiries->where('type', 'listing')),
            'inquiry_places' => $byPlace,
            'inquiry_buckets' => $buckets,
            'replies' => $this->replies($inquiries),
        ];
    }

    /**
     * Every client inquiry, one row each, with what the reply section and the "looking for" section
     * need: where it was sent, the listing's category / type / subtype / price / beds / baths (listing
     * inquiries), the conversation status, and whether the agent — or anyone but the client — wrote.
     * The agent is the conversation's assigned agent for a listing inquiry, the chat's target user for
     * a direct one.
     */
    /**
     * "Did the agent reply?" for inquiries sent between two Manila dates (inclusive) — the reply section's
     * own date filter.
     */
    public function repliesBetween(string $start, string $end): array
    {
        $ids = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('roles.name', 'client')->pluck('users.id')->all();
        $from = now(self::TZ)->parse($start, self::TZ)->startOfDay()->utc();
        $to = now(self::TZ)->parse($end, self::TZ)->endOfDay()->utc();

        return $this->replies($this->inquiryRows($ids, [$from, $to]));
    }

    /** @param  array{0: \DateTimeInterface, 1: \DateTimeInterface}|null  $between  chats.created_at range (UTC) */
    private function inquiryRows(array $ids, ?array $between = null)
    {
        $agent = "CASE WHEN chats.type = 'agent' THEN chats.type_id ELSE conversations.agent_user_id END";

        return DB::table('chats')
            ->leftJoin('conversations', fn ($j) => $j->on('conversations.chat_id', '=', 'chats.id')->whereNull('conversations.deleted_at'))
            ->leftJoin('listings', fn ($j) => $j->on('listings.id', '=', 'chats.type_id')->where('chats.type', 'listing'))
            ->leftJoin('categories', 'categories.id', '=', 'listings.category_id')
            ->leftJoin('properties', 'properties.id', '=', 'listings.property_id')
            ->leftJoin('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->leftJoin('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->whereNull('chats.deleted_at')
            ->whereIn('chats.type', ['listing', 'agent'])
            ->whereIn('chats.user_id', $ids)
            ->when($between, fn ($q) => $q->whereBetween('chats.created_at', $between))
            ->get([
                'chats.user_id', 'chats.type', 'chats.source', 'chats.created_at',
                'conversations.status',
                DB::raw("{$agent} as agent_user_id"),
                DB::raw("EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = conversations.id AND m.user_id = {$agent}) as agent_replied"),
                DB::raw('EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = conversations.id AND m.user_id <> chats.user_id) as anyone_replied'),
                'categories.name as category', 'property_types.name as ptype', 'property_subtypes.name as subtype',
                'listings.price', 'property_attributes.bedroom_count as beds', 'property_attributes.bathroom_count as baths',
            ])
            ->each(function ($r) {
                $r->place = $r->type === 'listing' ? 'listing'
                    : ($r->source === 'agent_profile' ? 'agent_profile' : ($r->source === 'agent_page' ? 'agent_website' : 'agent_unrecorded'));
            });
    }

    /**
     * Did the agent reply? Per place, each inquiry is one of:
     *   - replied       : the agent sent at least one message
     *   - other_replied : the agent didn't, a team leader / admin did
     *   - no_reply      : it reached the agent and nobody but the client has written
     *   - pending       : waiting for review (listing inquiries), the agent hasn't received it yet
     *   - other         : closed / rejected before a reply
     * Replies by phone / WhatsApp happen outside the chat and can't be seen here.
     */
    private function replies($inquiries): array
    {
        $empty = ['replied' => 0, 'other_replied' => 0, 'no_reply' => 0, 'pending' => 0, 'other' => 0];
        $out = array_fill_keys(self::PLACES, $empty);
        foreach ($inquiries as $r) {
            $reached = $r->status === 'accepted' && $r->agent_user_id;
            $key = $reached
                ? ($r->agent_replied ? 'replied' : ($r->anyone_replied ? 'other_replied' : 'no_reply'))
                : ($r->status === 'pending' ? 'pending' : 'other');
            $out[$r->place][$key]++;
        }

        return $out;
    }

    /**
     * What clients look for, from their listing inquiries: categories (with average price), and per
     * category the types → subtypes, bedrooms, bathrooms and bed & bath combos, each with its average
     * price. Prices are compared within a category only (sale vs rent prices don't mix).
     */
    private function looking($rows): array
    {
        $byCategory = $rows->filter(fn ($r) => $r->category)->groupBy('category')->sortByDesc(fn ($g) => $g->count());

        $categories = $byCategory->map(fn ($g, $name) => ['label' => $name, 'value' => $g->count(), 'avg_price' => self::avgPrice($g)])->values()->all();
        $detail = $byCategory->map(function ($g) {
            $types = $g->filter(fn ($r) => $r->ptype)->groupBy('ptype')
                ->map(fn ($t, $name) => self::stat($name, $t) + [
                    'subtypes' => $t->filter(fn ($r) => $r->subtype)->groupBy('subtype')
                        ->map(fn ($s, $sub) => self::stat($sub, $s))->sortByDesc('value')->values()->all(),
                ])
                ->sortByDesc('value')->values()->all();

            // 0 bedrooms / bathrooms = not applicable (lots, land), so those listings sit out here.
            $count = fn ($col, $cap) => $g->filter(fn ($r) => (int) $r->{$col} > 0)
                ->groupBy(fn ($r) => (int) $r->{$col} >= $cap ? "{$cap}+" : (string) (int) $r->{$col})
                ->map(fn ($s, $label) => self::stat((string) $label, $s))
                ->sortBy(fn ($s) => (int) $s['label'])->values()->all();
            $combos = $g->filter(fn ($r) => (int) $r->beds > 0 && (int) $r->baths > 0)
                ->groupBy(fn ($r) => min((int) $r->beds, 5).(((int) $r->beds) >= 5 ? '+' : '').' bed · '.min((int) $r->baths, 5).(((int) $r->baths) >= 5 ? '+' : '').' bath')
                ->map(fn ($s, $label) => self::stat($label, $s))
                ->sortByDesc('value')->values()->all();

            return ['types' => $types, 'bedrooms' => $count('beds', 5), 'bathrooms' => $count('baths', 5), 'combos' => $combos];
        })->all();

        return ['categories' => $categories, 'by_category' => $detail];
    }

    /** ['label', 'value' => inquiries, 'avg_price'] for a group of inquiry rows. */
    private static function stat(string $label, $rows): array
    {
        return ['label' => $label, 'value' => $rows->count(), 'avg_price' => self::avgPrice($rows)];
    }

    /**
     * Average listing price, ignoring blanks and the most extreme 5% at each end when there are 20+
     * prices — a few listings carry typos (₱150B) that would otherwise swamp the average.
     */
    private static function avgPrice($rows): ?float
    {
        $p = $rows->pluck('price')->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0)->sort()->values();
        if ($p->isEmpty()) {
            return null;
        }
        if ($p->count() >= 20) {
            $cut = (int) floor($p->count() * 0.05);
            $p = $p->slice($cut, $p->count() - 2 * $cut);
        }

        return round($p->avg());
    }

    /**
     * The journey for each period, following the NEW people of that period: anonymous devices that first
     * visited in it (and never made an account), and clients who signed up in it — then how many of those
     * clients saved a listing, inquired, and inquired more than once (at any time since).
     */
    private function journeyRanges($clients, array $per, $saved, array $anonymous): array
    {
        $starts = ['30d' => time() - 30 * 86400, '90d' => time() - 90 * 86400, '12m' => now(self::TZ)->subMonths(12)->getTimestamp(), 'all' => null];
        $out = [];
        foreach ($starts as $key => $from) {
            $cohort = $from === null ? $clients : $clients->filter(fn ($c) => $c->created_at && strtotime($c->created_at.' UTC') >= $from);
            $ids = $cohort->keys();
            $out[$key] = [
                'anonymous' => $anonymous['ranges'][$key],
                'clients' => $cohort->count(),
                'saved' => $ids->filter(fn ($id) => isset($saved[$id]))->count(),
                'inquired' => $ids->filter(fn ($id) => isset($per[$id]))->count(),
                'repeat' => $ids->filter(fn ($id) => ($per[$id]['n'] ?? 0) > 1)->count(),
            ];
        }

        return $out;
    }

    /**
     * Anonymous visitors — devices that never logged in while browsing and aren't saved on any account
     * (signup stamps users.visitor_id) — counted by the day each FIRST visited. One pass over visits
     * (MIN(created_at) per device) gives every journey range.
     */
    private function anonymousStats(): array
    {
        $at = fn (int $ts) => date('Y-m-d H:i:s', $ts);
        $marks = ['r30d' => time() - 30 * 86400, 'r90d' => time() - 90 * 86400, 'r12m' => now(self::TZ)->subMonths(12)->getTimestamp()];
        $cols = ['COUNT(*) as total'];
        $bindings = [];
        foreach ($marks as $key => $ts) {
            $cols[] = "SUM(first >= ?) as {$key}";
            $bindings[] = $at($ts);
        }

        $firsts = DB::table('visits')
            ->whereNotNull('visitor_id')
            ->groupBy('visitor_id')
            ->havingRaw('MAX(user_id) IS NULL')
            ->selectRaw('visitor_id, MIN(created_at) as first');
        $row = DB::query()->fromSub($firsts, 't')
            ->whereNotIn('t.visitor_id', fn ($q) => $q->select('visitor_id')->from('users')->whereNotNull('visitor_id'))
            ->selectRaw(implode(', ', $cols), $bindings)
            ->first();

        $from = now(self::TZ)->startOfMonth()->subMonths(11);
        $monthly = DB::query()->fromSub($firsts, 't')
            ->whereNotIn('t.visitor_id', fn ($q) => $q->select('visitor_id')->from('users')->whereNotNull('visitor_id'))
            ->where('t.first', '>=', $from->copy()->utc())
            ->groupBy('m')
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(t.first, '+00:00', '".self::OFFSET."'), '%Y-%m') as m, COUNT(*) as c")
            ->pluck('c', 'm');

        return [
            'ranges' => ['30d' => (int) $row->r30d, '90d' => (int) $row->r90d, '12m' => (int) $row->r12m, 'all' => (int) $row->total],
            'monthly' => $this->months($from, $monthly),
        ];
    }

    /**
     * The journey cards' trend lines: per step, how many people reached it each month, last 12 months
     * (Manila) — anonymous devices first seen, sign-ups, first saved listing, first inquiry, second inquiry.
     */
    private function journeyTrend($inquiries, array $ids, array $anonymousMonthly): array
    {
        $from = now(self::TZ)->startOfMonth()->subMonths(11);
        $month = fn (string $utc) => now(self::TZ)->parse($utc.' UTC')->setTimezone(self::TZ)->format('Y-m');

        $inquired = $repeat = [];
        foreach ($inquiries->groupBy('user_id') as $rows) {
            $at = $rows->pluck('created_at')->filter()->sort()->values();
            if ($at->isNotEmpty()) {
                $m = $month($at[0]);
                $inquired[$m] = ($inquired[$m] ?? 0) + 1;
            }
            if ($at->count() > 1) {
                $m = $month($at[1]);
                $repeat[$m] = ($repeat[$m] ?? 0) + 1;
            }
        }

        $firstSave = DB::table('favorites')->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, MIN(created_at) as first');
        $saved = DB::query()->fromSub($firstSave, 'f')
            ->where('f.first', '>=', $from->copy()->utc())
            ->groupBy('m')
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(f.first, '+00:00', '".self::OFFSET."'), '%Y-%m') as m, COUNT(*) as c")
            ->pluck('c', 'm');

        $values = fn (array $rows) => array_column($rows, 'value');

        return [
            'anonymous' => $values($anonymousMonthly),
            'clients' => $values($this->monthlySignups()),
            'saved' => $values($this->months($from, $saved)),
            'inquired' => $values($this->months($from, $inquired)),
            'repeat' => $values($this->months($from, $repeat)),
        ];
    }

    /** New clients per month, last 12 months (Manila), zero-filled. */
    private function monthlySignups(): array
    {
        $from = now(self::TZ)->startOfMonth()->subMonths(11);
        $local = "DATE_FORMAT(CONVERT_TZ(users.created_at, '+00:00', '".self::OFFSET."'), '%Y-%m')";
        $counts = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->where('users.created_at', '>=', $from->copy()->utc())
            ->groupBy('m')
            ->selectRaw("{$local} as m, COUNT(*) as c")
            ->pluck('c', 'm');

        return $this->months($from, $counts);
    }

    /**
     * Returning clients per month, last 12 months: clients who logged in that month and had signed up
     * before it began (Manila).
     */
    private function monthlyReturning(): array
    {
        $from = now(self::TZ)->startOfMonth()->subMonths(11);
        $month = "DATE_FORMAT(CONVERT_TZ(login_logs.logged_in_at, '+00:00', '".self::OFFSET."'), '%Y-%m')";
        $counts = DB::table('login_logs')
            ->join('users', 'users.id', '=', 'login_logs.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'client')
            ->where('login_logs.logged_in_at', '>=', $from->copy()->utc())
            ->whereRaw("CONVERT_TZ(users.created_at, '+00:00', '".self::OFFSET."') < CONCAT({$month}, '-01')")
            ->groupBy('m')
            ->selectRaw("{$month} as m, COUNT(DISTINCT login_logs.user_id) as c")
            ->pluck('c', 'm');

        return $this->months($from, $counts);
    }

    /** Client inquiries (listing + direct) sent per month, last 12 months (Manila). */
    private function monthlyInquiries(array $ids): array
    {
        $from = now(self::TZ)->startOfMonth()->subMonths(11);
        $local = "DATE_FORMAT(CONVERT_TZ(chats.created_at, '+00:00', '".self::OFFSET."'), '%Y-%m')";
        $counts = DB::table('chats')
            ->whereNull('deleted_at')
            ->whereIn('type', ['listing', 'agent'])
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', $from->copy()->utc())
            ->groupBy('m')
            ->selectRaw("{$local} as m, COUNT(*) as c")
            ->pluck('c', 'm');

        return $this->months($from, $counts);
    }

    private function months($from, $counts): array
    {
        $out = [];
        for ($m = $from->copy(); $m->lte(now(self::TZ)); $m->addMonth()) {
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'value' => (int) ($counts[$m->format('Y-m')] ?? 0)];
        }

        return $out;
    }

    /**
     * Clients by the channel of their first visit. Looks up only the clients' own devices (a correlated
     * MIN(id) per visitor_id on the visitor_id index) rather than grouping the whole visits table.
     *
     * @param  string[]  $visitorIds
     */
    private function firstTouch(array $visitorIds, int $clientCount): array
    {
        $byChannel = [];
        foreach (array_chunk($visitorIds, 1000) as $chunk) {
            $rows = DB::table('visits as v')
                ->whereIn('v.visitor_id', $chunk)
                ->whereRaw('v.id = (SELECT MIN(f.id) FROM visits f WHERE f.visitor_id = v.visitor_id)')
                ->groupBy('v.channel')
                ->selectRaw('v.channel as channel, COUNT(*) as c')
                ->pluck('c', 'channel');
            foreach ($rows as $channel => $n) {
                $byChannel[$channel] = ($byChannel[$channel] ?? 0) + (int) $n;
            }
        }
        arsort($byChannel);
        $byChannel['unknown'] = max(0, $clientCount - array_sum($byChannel));

        return collect($byChannel)->map(fn ($n, $channel) => ['channel' => $channel, 'value' => $n])->values()->all();
    }

    /** Top client cities (user_info, each client once). */
    private function cities(array $ids): array
    {
        return DB::table('user_info')
            ->whereIn('user_id', $ids)
            ->whereNotNull('city')
            ->whereNotIn('city', ['', 'Unknown'])
            ->groupBy('city')
            ->orderByDesc(DB::raw('COUNT(DISTINCT user_id)'))
            ->limit(30)
            ->get(['city', DB::raw('COUNT(DISTINCT user_id) as value')])
            ->map(fn ($r) => ['label' => $r->city, 'value' => (int) $r->value])
            ->all();
    }
}
