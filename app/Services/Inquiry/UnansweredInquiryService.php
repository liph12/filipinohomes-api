<?php

namespace App\Services\Inquiry;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin "Unanswered Inquiries": approved listing inquiries nobody on our side
 * has replied to yet — the queue the office team works from when a client
 * calls after the Inquiry Filipinohomes Assistant gave them the office number.
 *
 * "Replied" uses the same rule as the assistant: any message in the thread not
 * written by the client and not automated (agent, team leader or admin). The
 * assistant's own messages don't count, so an inquiry stays here until a person
 * answers it.
 *
 * Filters: all · office_number (the assistant already sent the office-number
 * message — these clients may be calling) · waiting (not yet) · inactive_agent
 * (the listing owner never logged in, or no login and no activity for the
 * assistant's "inactive after" days — same rule as InquiryAiReplyService).
 */
class UnansweredInquiryService
{
    public const FILTERS = ['all', 'office_number', 'waiting', 'active_agent', 'inactive_agent'];

    private function base(): Builder
    {
        return DB::table('conversations as c')
            ->join('chats as ch', 'ch.id', '=', 'c.chat_id')
            ->where('ch.type', 'listing')
            ->whereNull('ch.deleted_at')
            ->whereNull('c.deleted_at')
            ->where('c.status', 'accepted')
            ->whereNotNull('c.agent_user_id')
            ->whereColumn('ch.user_id', '!=', 'c.agent_user_id')
            ->whereNotExists(fn ($m) => $m->select(DB::raw(1))
                ->from('messages as m')
                ->whereColumn('m.conversation_id', 'c.id')
                ->whereColumn('m.user_id', '!=', 'ch.user_id')
                ->where('m.automated', false))
            ->leftJoin('inquiry_ai_replies as esc', function ($j) {
                $j->on('esc.conversation_id', '=', 'c.id')->where('esc.stage', 'escalation')->where('esc.status', 'sent');
            });
    }

    private function applyFilter(Builder $q, string $filter): Builder
    {
        return match ($filter) {
            'office_number' => $q->whereNotNull('esc.id'),
            'waiting' => $q->whereNull('esc.id'),
            'inactive_agent' => $this->whereAgentInactive($q),
            'active_agent' => $this->whereAgentActive($q),
            default => $q,
        };
    }

    /** Same rule as InquiryAiReplyService::agentInactive, in SQL. */
    private function whereAgentInactive(Builder $q): Builder
    {
        $svc = app(InquiryAiReplyService::class);
        $cutoff = Carbon::now()->subDays($svc->settings()['inactive_agent_days']);

        return $q
            ->whereNotExists(fn ($l) => $l->select(DB::raw(1))
                ->from('login_logs as il')
                ->whereColumn('il.user_id', 'c.agent_user_id')
                ->where('il.logged_in_at', '>=', $cutoff))
            ->whereNotExists(fn ($u) => $u->select(DB::raw(1))
                ->from('users as iu')
                ->whereColumn('iu.id', 'c.agent_user_id')
                ->where('iu.last_online_at', '>=', $cutoff));
    }

    /** The opposite of whereAgentInactive: a login or activity within the inactive-after days. */
    private function whereAgentActive(Builder $q): Builder
    {
        $cutoff = Carbon::now()->subDays(app(InquiryAiReplyService::class)->settings()['inactive_agent_days']);

        return $q->where(fn ($w) => $w
            ->whereExists(fn ($l) => $l->select(DB::raw(1))
                ->from('login_logs as al')
                ->whereColumn('al.user_id', 'c.agent_user_id')
                ->where('al.logged_in_at', '>=', $cutoff))
            ->orWhereExists(fn ($u) => $u->select(DB::raw(1))
                ->from('users as auu')
                ->whereColumn('auu.id', 'c.agent_user_id')
                ->where('auu.last_online_at', '>=', $cutoff)));
    }

    private function applySearch(Builder $q, ?string $search): Builder
    {
        $search = trim((string) $search);
        if ($search === '') {
            return $q;
        }
        $like = '%'.$search.'%';

        // Inquiry number = the last number of the chat link (chats.id).
        // "#1350" → that inquiry only. A short plain number ("1350") → that
        // inquiry or a property code ending in it ("CEB-1350"); long numbers
        // (phones) fall through to the normal search.
        if (preg_match('/^#\s*(\d{1,10})$/', $search, $m)) {
            return $q->where('ch.id', (int) $m[1]);
        }
        if (preg_match('/^\d{1,7}$/', $search)) {
            return $q->where(fn ($w) => $w->where('ch.id', (int) $search)->orWhere('l.code', 'like', $like));
        }

        return $q->where(fn ($w) => $w
            ->where('cu.name', 'like', $like)
            ->orWhere('cu.email', 'like', $like)
            ->orWhere('cu.mobile_no', 'like', $like)
            ->orWhere('l.name', 'like', $like)
            ->orWhere('l.code', 'like', $like)
            ->orWhere('au.name', 'like', $like));
    }

    private function withPeople(Builder $q): Builder
    {
        return $q->leftJoin('users as cu', 'cu.id', '=', 'ch.user_id')
            ->leftJoin('users as au', 'au.id', '=', 'c.agent_user_id')
            ->leftJoin('listings as l', 'l.id', '=', 'ch.type_id');
    }

    /** @return array{all:int, office_number:int, waiting:int} */
    public function counts(?string $search = null): array
    {
        $out = [];
        foreach (self::FILTERS as $f) {
            $out[$f] = (int) $this->applySearch($this->applyFilter($this->withPeople($this->base()), $f), $search)
                ->count(DB::raw('DISTINCT c.id'));
        }

        return $out;
    }

    public function paginate(string $filter, ?string $search, int $perPage = 25): LengthAwarePaginator
    {
        $q = $this->applySearch($this->applyFilter($this->withPeople($this->base()), $filter), $search)
            ->leftJoin('agents as a', 'a.user_id', '=', 'c.agent_user_id')
            ->leftJoin('agents as ca', 'ca.user_id', '=', 'ch.user_id') // the inquirer may be an agent too
            ->leftJoin('roles as cr', 'cr.id', '=', 'cu.role_id')
            ->leftJoin('roles as ar', 'ar.id', '=', 'au.role_id')
            ->leftJoin('properties as p', 'p.id', '=', 'l.property_id')
            ->leftJoin('barangays as b', 'b.id', '=', 'p.address_id')
            ->leftJoin('cities as ci', 'ci.id', '=', 'b.city_id')
            ->leftJoin('provinces as pr', 'pr.id', '=', 'ci.province_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'l.category_id')
            ->leftJoin('property_attributes as pa', 'pa.id', '=', 'p.property_attribute_id')
            ->leftJoin('property_subtypes as pst', 'pst.id', '=', 'pa.property_subtype_id')
            ->leftJoin('property_types as pt', 'pt.id', '=', 'pst.property_type_id')
            ->leftJoin('inquiry_ai_replies as ack', function ($j) {
                $j->on('ack.conversation_id', '=', 'c.id')->where('ack.stage', 'ack')->where('ack.status', 'sent');
            })
            ->select([
                'c.id as conversation_id', 'c.chat_id', 'c.reviewed_at', 'c.created_at',
                'cu.name as client_name', 'cu.email as client_email', 'cu.mobile_no as client_mobile', 'cu.avatar as client_avatar', 'ca.first_name as client_first_name', 'ca.last_name as client_last_name', 'cr.name as client_role',
                DB::raw(self::leaderSql('ch.user_id').' as client_is_leader'), 'cu.last_online_at as client_last_online_at',
                DB::raw('(SELECT COUNT(*) FROM login_logs cl WHERE cl.user_id = ch.user_id) as client_login_count'),
                DB::raw('(SELECT MAX(cl2.logged_in_at) FROM login_logs cl2 WHERE cl2.user_id = ch.user_id) as client_last_login_at'),
                'au.name as agent_name', 'ar.name as agent_role',
                DB::raw(self::leaderSql('c.agent_user_id').' as agent_is_leader'), 'au.avatar as agent_avatar', 'au.email as agent_email', 'au.last_online_at as agent_last_online_at',
                // Same sources as the Agents page (login_logs): total logins + latest login.
                DB::raw('(SELECT COUNT(*) FROM login_logs ll WHERE ll.user_id = c.agent_user_id) as agent_login_count'),
                DB::raw('(SELECT MAX(ll2.logged_in_at) FROM login_logs ll2 WHERE ll2.user_id = c.agent_user_id) as agent_last_login_at'), 'a.id as owner_agent_id', 'a.first_name as agent_first_name', 'a.last_name as agent_last_name', 'a.mobile_no as agent_mobile', 'a.whats_app_no as agent_whatsapp',
                'l.name as listing_name', 'l.code as listing_code', 'l.price as listing_price', 'l.featured_photo', 'p.photos as property_photos',
                'cat.name as category', 'pt.name as property_type', 'pst.name as property_subtype',
                'b.name as barangay', 'ci.name as city', 'pr.name as province', 'p.address as address',
                'ack.created_at as first_reply_at', 'esc.created_at as office_number_at', 'esc.source as office_number_source',
                DB::raw('(SELECT m2.body FROM messages m2 WHERE m2.conversation_id = c.id AND m2.user_id = ch.user_id AND m2.automated = 0 ORDER BY m2.id DESC LIMIT 1) as last_client_message'),
                DB::raw('(SELECT MAX(m3.created_at) FROM messages m3 WHERE m3.conversation_id = c.id AND m3.user_id = ch.user_id AND m3.automated = 0) as last_client_message_at'),
            ])
            ->orderByDesc('c.reviewed_at')
            ->orderByDesc('c.id');

        $page = $q->paginate($perPage);
        $teams = $this->teamsFor($page->getCollection()->pluck('owner_agent_id')->filter()->unique()->values()->all());
        $page->getCollection()->transform(fn ($r) => [
            ...$this->row($r),
            'team' => $teams[(int) $r->owner_agent_id] ?? null,
        ]);

        return $page;
    }

    /**
     * The listing owner's active team and its leader (team_agents.is_leader —
     * the same rule as TeamLeadershipService), with the leader's contact and
     * login info. One batch for the whole page.
     *
     * @param  int[]  $agentIds
     * @return array<int, array<string, mixed>> keyed by the owner's agent id
     */
    private function teamsFor(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }
        $memberships = DB::table('team_agents as ta')
            ->join('teams as t', 't.id', '=', 'ta.team_id')
            ->whereIn('ta.agent_id', $agentIds)
            ->where('ta.status', 'active')
            ->orderBy('ta.id')
            ->get(['ta.agent_id', 't.id as team_id', 't.name as team_name']);
        if ($memberships->isEmpty()) {
            return [];
        }

        $leaders = DB::table('team_agents as tl')
            ->join('agents as ag', 'ag.id', '=', 'tl.agent_id')
            ->join('users as u', 'u.id', '=', 'ag.user_id')
            ->whereIn('tl.team_id', $memberships->pluck('team_id')->unique())
            ->where('tl.is_leader', true)
            ->where('tl.status', 'active')
            ->orderBy('tl.id')
            ->get([
                'tl.team_id', 'ag.first_name', 'ag.last_name', 'ag.mobile_no', 'ag.whats_app_no',
                'u.id as user_id', 'u.name', 'u.email', 'u.avatar', 'u.last_online_at',
                DB::raw('(SELECT COUNT(*) FROM login_logs l1 WHERE l1.user_id = u.id) as login_count'),
                DB::raw('(SELECT MAX(l2.logged_in_at) FROM login_logs l2 WHERE l2.user_id = u.id) as last_login_at'),
            ])
            ->unique('team_id')
            ->keyBy('team_id');

        $out = [];
        foreach ($memberships->unique('agent_id') as $m) {
            $l = $leaders[$m->team_id] ?? null;
            $out[(int) $m->agent_id] = [
                'name' => $m->team_name,
                'leader' => $l ? [
                    'name' => $this->displayName($l->first_name, $l->last_name, $l->name),
                    'avatar' => $l->avatar,
                    'email' => $l->email,
                    'mobile' => $l->mobile_no,
                    'whatsapp' => $l->whats_app_no,
                    'login_count' => (int) $l->login_count,
                    'last_login_at' => $l->last_login_at ? Carbon::parse($l->last_login_at)->toIso8601String() : null,
                    'last_active_at' => $l->last_online_at ? Carbon::parse($l->last_online_at)->toIso8601String() : null,
                ] : null,
            ];
        }

        return $out;
    }

    /**
     * First + last name from the agent profile — no middle name ("Mark
     * Lawrince Sargado", not "Mark Lawrince Arpilleda Sargado"). Falls back to
     * the account name for people without an agent profile (most clients).
     */
    private function displayName(?string $first, ?string $last, ?string $account): ?string
    {
        $name = trim(trim((string) $first).' '.trim((string) $last));

        return $name !== '' ? $name : $account;
    }

    /** Same rule as TeamLeadershipService::isTeamLeader: an active leader row in team_agents. */
    private static function leaderSql(string $userColumn): string
    {
        return "EXISTS (SELECT 1 FROM team_agents ta JOIN agents ag ON ag.id = ta.agent_id WHERE ag.user_id = {$userColumn} AND ta.is_leader = 1 AND ta.status = 'active')";
    }

    /** Role key for the badge: admin / team_leader / agent / client / editor / secretary. */
    private function roleLabel(?string $role, mixed $isLeader): ?string
    {
        if ($role === 'agent' && (int) $isLeader === 1) {
            return 'team_leader';
        }

        return $role;
    }

    private function latest(?string ...$times): ?Carbon
    {
        $parsed = array_map(fn ($t) => Carbon::parse($t), array_filter($times));

        return $parsed ? max($parsed) : null;
    }

    /** First URL from a photo column — a JSON array or a plain URL string. */
    private function firstPhoto(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $first = is_array($decoded) ? ($decoded[0] ?? null) : $raw;

        return is_string($first) && str_starts_with($first, 'http') ? $first : null;
    }

    private function row(object $r): array
    {
        $location = implode(', ', array_filter([$r->barangay, $r->city, $r->province]))
            ?: (preg_replace('/,\s*Philippines$/i', '', (string) $r->address) ?: null);
        $approvedAt = $r->reviewed_at ? Carbon::parse($r->reviewed_at) : null;

        return [
            'conversation_id' => (int) $r->conversation_id,
            'chat_id' => (int) $r->chat_id,
            // The admin chat URL: /admin/listing-inquiries/{slug}
            'chat_slug' => ($r->listing_name ? Str::slug($r->listing_name) : 'chat').'-'.$r->chat_id,
            'approved_at' => $approvedAt?->toIso8601String(),
            'waiting_minutes' => $approvedAt ? (int) $approvedAt->diffInMinutes(now()) : null,
            'client' => [
                // Clients use their account name; an agent/admin who inquired uses
                // their agent-profile first + last name (no middle name).
                'name' => $r->client_role === 'client'
                    ? $r->client_name
                    : $this->displayName($r->client_first_name, $r->client_last_name, $r->client_name),
                'email' => $r->client_email,
                'mobile' => $r->client_mobile,
                'avatar' => $r->client_avatar,
                'role' => $this->roleLabel($r->client_role, $r->client_is_leader),
                'login_count' => (int) $r->client_login_count,
                'last_login_at' => $r->client_last_login_at ? Carbon::parse($r->client_last_login_at)->toIso8601String() : null,
                'last_active_at' => $r->client_last_online_at ? Carbon::parse($r->client_last_online_at)->toIso8601String() : null,
            ],
            'agent' => [
                'name' => $this->displayName($r->agent_first_name, $r->agent_last_name, $r->agent_name),
                'avatar' => $r->agent_avatar,
                'role' => $this->roleLabel($r->agent_role, $r->agent_is_leader),
                'email' => $r->agent_email,
                'login_count' => (int) $r->agent_login_count,
                'last_login_at' => $r->agent_last_login_at ? Carbon::parse($r->agent_last_login_at)->toIso8601String() : null,
                'last_active_at' => $r->agent_last_online_at ? Carbon::parse($r->agent_last_online_at)->toIso8601String() : null,
                // Did the agent log in / use the app after this inquiry was approved?
                // (They could have seen it and still not replied.)
                'active_since_inquiry' => $approvedAt && $this->latest($r->agent_last_login_at, $r->agent_last_online_at)?->greaterThan($approvedAt),
                'mobile' => $r->agent_mobile,
                'whatsapp' => $r->agent_whatsapp,
            ],
            'listing' => [
                'name' => $r->listing_name,
                'code' => $r->listing_code,
                'location' => $location,
                'category' => $r->category,      // For Sale / For Rent / Foreclosed
                'type' => $r->property_type,     // e.g. Condominium
                'subtype' => $r->property_subtype, // e.g. Penthouse
                'price' => is_numeric($r->listing_price) && (float) $r->listing_price > 0 ? (float) $r->listing_price : null,
                'photo' => $this->firstPhoto($r->featured_photo) ?? $this->firstPhoto($r->property_photos),
            ],
            'last_client_message' => $r->last_client_message ? Str::limit(trim($r->last_client_message), 160) : null,
            'last_client_message_at' => $r->last_client_message_at ? Carbon::parse($r->last_client_message_at)->toIso8601String() : null,
            'first_reply_at' => $r->first_reply_at ? Carbon::parse($r->first_reply_at)->toIso8601String() : null,
            'office_number_at' => $r->office_number_at ? Carbon::parse($r->office_number_at)->toIso8601String() : null,
            // Given in the first reply because the agent never logged in / was inactive (not after the 3h wait).
            'office_number_reason' => $r->office_number_at
                ? ($r->office_number_source === InquiryAiReplyService::SOURCE_INACTIVE_AGENT ? 'agent_inactive' : 'waited')
                : null,
        ];
    }
}
