<?php

namespace App\Services\Reports\Executive;

use App\Support\OfficeRegionMap;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An agent's leads, from every source FH records, as one row shape — the
 * `lead` family of the report.
 *
 *   listing     a client asked about one of the agent's listings (the chat
 *               inquiries the rest of the report counts)
 *   direct      a client messaged the agent straight from their profile or
 *               My Page (`chats` type `agent`; type_id is the agent's USER id)
 *   brief       a client filled in the agent's Buyer & Seller Link brief
 *   open_house  a client registered through the agent's Buyer Form
 *
 * Each source becomes rows of `lead_key` (record id × 10 + source digit, so
 * keys never collide across sources), `source`, `agent_id`, `office` (the
 * agent's raw region), `at` (when it came in), `answered` / `waiting` (chat
 * sources only) and `since` (when a waiting lead started waiting). Sources are
 * UNION ALL-ed into one derived table `ld` and filtered there, so a count, a
 * fold in ReportContext and a drill list read the very same rows.
 *
 * Clients only, as with inquiries: a message or registration from an agent or
 * admin account is a colleague, not a lead. Briefs carry no user, so every
 * brief on an agent's link counts; one from an admin's own link has no agent
 * and is left out. Registrations on a deleted Buyer Form still count — the
 * client did register.
 *
 * A user can own two agent rows (agents.user_id isn't unique), so a direct
 * message is credited through one derived table: each user's lowest live
 * agent id — never a direct join, which would count the lead twice.
 */
final class LeadQueries
{
    public const SOURCES = ['listing' => 1, 'direct' => 2, 'brief' => 3, 'open_house' => 4];

    public const LABELS = [
        'listing' => 'Asked about a listing',
        'direct' => 'Messaged the agent',
        'brief' => 'Buyer & Seller Link',
        'open_house' => 'Open-house registration',
    ];

    /** Lead counts per agent that select each bucket of the `leads` drill filter. */
    public const BUCKETS = ['1' => [1, 1], '2-3' => [2, 3], '4-9' => [4, 9], '10-plus' => [10, null]];

    private const LIVE = ['active', 'updated'];

    public function __construct(
        private readonly ReportQueries $q,
        private readonly PeopleQueries $people,
        private readonly TeamDirectory $teams,
    ) {}

    /** The rows behind a lead-family metric, as derived table `ld`, with the drill filters applied. */
    public function source(string $key, ReportRange $range, Carbon $now, array $filters = []): Builder
    {
        $rows = match ($key) {
            'direct.received' => DB::query()->fromSub($this->direct($range, $now), 'ld'),
            'direct.answered' => DB::query()->fromSub($this->direct($range, $now), 'ld')->where('ld.answered', 1),
            'direct.waiting' => DB::query()->fromSub($this->direct($range, $now), 'ld')->where('ld.waiting', 1),
            'briefs.received' => DB::query()->fromSub($this->briefs($range), 'ld'),
            'open_house.registrations' => DB::query()->fromSub($this->openHouse($range), 'ld'),
            'leads.all' => DB::query()->fromSub($this->all($range, $now), 'ld'),
            'leads.reached' => DB::query()->fromSub($this->chat($range, $now, 'reached'), 'ld'),
            'leads.answered' => DB::query()->fromSub($this->chat($range, $now, 'answered'), 'ld'),
            'leads.waiting' => DB::query()->fromSub($this->chat($range, $now, 'waiting'), 'ld'),
            'leads.to_idle_agents' => DB::query()->fromSub($this->all($range, $now), 'ld')
                ->leftJoinSub($this->busyAgents($range), 'busy', 'busy.agent_id', '=', 'ld.agent_id')
                ->whereNull('busy.agent_id'),
        };

        return $this->applyFilters($rows, $filters);
    }

    /** Every lead of the range, all four sources. */
    public function all(ReportRange $range, Carbon $now): Builder
    {
        return $this->listing($range)
            ->unionAll($this->direct($range, $now))
            ->unionAll($this->briefs($range))
            ->unionAll($this->openHouse($range));
    }

    /**
     * The chat leads of the range in one lifecycle state — `reached` (could be
     * answered: the inquiry reached its agent, or a direct message arrived),
     * `answered`, or `waiting` (never answered after 24 hours, as of now).
     * Listing inquiries come from the inquiry lifecycle, whose meaning of
     * "answered" is anyone but the client replying; a direct message counts
     * as answered once the agent themself replied.
     */
    public function chat(ReportRange $range, Carbon $now, string $state): Builder
    {
        $life = DB::query()->fromSub($this->q->lifecycle($range, $now), 't');
        $listing = match ($state) {
            'reached' => $life->where('t.reached_agent', 1),
            'answered' => $life->where('t.reached_agent', 1)->whereNotNull('t.last_side_at'),
            'waiting' => $life->where('t.bucket', 'no_reply'),
        };
        $listing->select([
            DB::raw('t.chat_id * 10 + '.self::SOURCES['listing'].' as lead_key'),
            DB::raw("'listing' as source"),
            't.agent_id',
            't.office',
            't.received_at as at',
            DB::raw('CASE WHEN t.last_side_at IS NOT NULL THEN 1 ELSE 0 END as answered'),
            DB::raw("CASE WHEN t.bucket = 'no_reply' THEN 1 ELSE 0 END as waiting"),
            DB::raw("CASE WHEN t.bucket = 'no_reply' THEN COALESCE(t.reviewed_at, t.conversation_created_at) END as since"),
        ]);

        $direct = DB::query()->fromSub($this->direct($range, $now), 'dl')->select('dl.*');
        if ($state !== 'reached') {
            $direct->where("dl.{$state}", 1);
        }

        return $listing->unionAll($direct);
    }

    // ── The four sources ────────────────────────────────────────────────────

    /** Listing inquiries received in the range — the report's `inquiries.received` rows. */
    public function listing(ReportRange $range): Builder
    {
        return $this->q->inquiries($range)->select([
            DB::raw('chats.id * 10 + '.self::SOURCES['listing'].' as lead_key'),
            DB::raw("'listing' as source"),
            'listings.agent_id as agent_id',
            'owner.region as office',
            'chats.created_at as at',
            DB::raw('0 as answered'),
            DB::raw('0 as waiting'),
            DB::raw('NULL as since'),
        ]);
    }

    /**
     * Direct messages opened by a client in the range. Answered = a live
     * message from the agent's own user in that chat; waiting = none yet,
     * 24 hours on (as of now).
     */
    public function direct(ReportRange $range, Carbon $now): Builder
    {
        $from = $range->fromUtc();
        $to = $range->toUtc();
        $h24 = $now->copy()->subDay()->utc()->format('Y-m-d H:i:s');

        $replies = DB::table('messages as dm')
            ->join('conversations as dmcv', 'dmcv.id', '=', 'dm.conversation_id')
            ->join('chats as dmch', 'dmch.id', '=', 'dmcv.chat_id')
            ->where('dmch.type', 'agent')
            ->where('dmch.created_at', '>=', $from)
            ->where('dmch.created_at', '<', $to)
            ->whereColumn('dm.user_id', 'dmch.type_id')
            ->whereIn('dm.status', self::LIVE)
            ->groupBy('dmch.id')
            ->select('dmch.id as chat_id', DB::raw('MIN(dm.created_at) as first_at'));

        return DB::table('chats as dmc')
            ->join('users as dmu', 'dmu.id', '=', 'dmc.user_id')
            ->join('roles as dmr', function ($join) {
                $join->on('dmr.id', '=', 'dmu.role_id')->where('dmr.name', 'client');
            })
            ->joinSub($this->agentOfUser(), 'dau', 'dau.user_id', '=', 'dmc.type_id')
            ->join('agents as da', 'da.id', '=', 'dau.agent_id')
            ->leftJoinSub($replies, 'dmr2', 'dmr2.chat_id', '=', 'dmc.id')
            ->where('dmc.type', 'agent')
            ->whereNull('dmc.deleted_at')
            ->where('dmc.created_at', '>=', $from)
            ->where('dmc.created_at', '<', $to)
            ->select([
                DB::raw('dmc.id * 10 + '.self::SOURCES['direct'].' as lead_key'),
                DB::raw("'direct' as source"),
                'da.id as agent_id',
                'da.region as office',
                'dmc.created_at as at',
                DB::raw('CASE WHEN dmr2.chat_id IS NOT NULL THEN 1 ELSE 0 END as answered'),
            ])
            ->selectRaw('CASE WHEN dmr2.chat_id IS NULL AND dmc.created_at <= ? THEN 1 ELSE 0 END as waiting', [$h24])
            ->selectRaw('CASE WHEN dmr2.chat_id IS NULL AND dmc.created_at <= ? THEN dmc.created_at END as since', [$h24]);
    }

    /** Buyer & Seller Link briefs sent to an agent's link in the range. */
    public function briefs(ReportRange $range): Builder
    {
        return DB::table('client_briefs as cb')
            ->join('agents as ba', 'ba.id', '=', 'cb.agent_id')
            ->where('cb.created_at', '>=', $range->fromUtc())
            ->where('cb.created_at', '<', $range->toUtc())
            ->select([
                DB::raw('cb.id * 10 + '.self::SOURCES['brief'].' as lead_key'),
                DB::raw("'brief' as source"),
                'ba.id as agent_id',
                'ba.region as office',
                'cb.created_at as at',
                DB::raw('0 as answered'),
                DB::raw('0 as waiting'),
                DB::raw('NULL as since'),
            ]);
    }

    /** Clients who registered through an agent's Buyer Form in the range. */
    public function openHouse(ReportRange $range): Builder
    {
        return DB::table('buyer_form_registrations as bfr')
            ->join('buyer_forms as bf', 'bf.id', '=', 'bfr.buyer_form_id')
            ->join('agents as oa', 'oa.id', '=', 'bf.agent_id')
            ->join('users as bru', 'bru.id', '=', 'bfr.user_id')
            ->join('roles as brr', function ($join) {
                $join->on('brr.id', '=', 'bru.role_id')->where('brr.name', 'client');
            })
            ->where('bfr.created_at', '>=', $range->fromUtc())
            ->where('bfr.created_at', '<', $range->toUtc())
            ->select([
                DB::raw('bfr.id * 10 + '.self::SOURCES['open_house'].' as lead_key'),
                DB::raw("'open_house' as source"),
                'oa.id as agent_id',
                'oa.region as office',
                'bfr.created_at as at',
                DB::raw('0 as answered'),
                DB::raw('0 as waiting'),
                DB::raw('NULL as since'),
            ]);
    }

    // ── Per agent ───────────────────────────────────────────────────────────

    /** (agent_id, n) — every lead of the range counted per agent. */
    public function perAgent(ReportRange $range, Carbon $now): Builder
    {
        return DB::query()->fromSub($this->all($range, $now), 'pa')
            ->whereNotNull('pa.agent_id')
            ->groupBy('pa.agent_id')
            ->select('pa.agent_id', DB::raw('COUNT(*) as n'));
    }

    /** Distinct agent ids holding a chat lead nobody answered (as of now). */
    public function waitingAgents(ReportRange $range, Carbon $now): Builder
    {
        return DB::query()->fromSub($this->chat($range, $now, 'waiting'), 'wa')
            ->whereNotNull('wa.agent_id')
            ->select('wa.agent_id')
            ->distinct();
    }

    /** Agent rows (any role or status) whose user did something recorded in the range. */
    public function busyAgents(ReportRange $range): Builder
    {
        return DB::table('agents as ba2')
            ->joinSub($this->people->activityIn($range), 'bact', 'bact.user_id', '=', 'ba2.user_id')
            ->select('ba2.id as agent_id')
            ->distinct();
    }

    /** Lead counts per agent → the `leads` bucket an agent with $n leads falls in. */
    public static function bucketOf(int $n): ?string
    {
        foreach (self::BUCKETS as $bucket => [$min, $max]) {
            if ($n >= $min && ($max === null || $n <= $max)) {
                return (string) $bucket; // '1' is an int key in a PHP array
            }
        }

        return null;
    }

    // ── Filters ─────────────────────────────────────────────────────────────

    /** The lead family's drill filters, on `ld` — the same rules as the listing family's. */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            switch ($key) {
                case 'office_region':
                    if ($value === 'unassigned') {
                        $query->where(function ($q) {
                            $q->whereNull('ld.office')
                                ->orWhere('ld.office', '')
                                ->orWhereNotIn('ld.office', OfficeRegionMap::REGIONS);
                        });
                    } else {
                        $query->where('ld.office', (string) $value);
                    }
                    break;
                case 'team_id':
                    (int) $value === 0
                        ? $query->whereNotIn('ld.agent_id', $this->teams->allMemberIds() ?: [0])
                        : $query->whereIn('ld.agent_id', $this->teams->members((int) $value) ?: [0]);
                    break;
                case 'agent_id':
                    $query->where('ld.agent_id', (int) $value);
                    break;
            }
        }

        return $query;
    }

    /** Each user's lowest live agent id — the one agent a direct message is credited to. */
    private function agentOfUser(): Builder
    {
        return DB::table('agents')
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->select('user_id', DB::raw('MIN(id) as agent_id'));
    }
}
