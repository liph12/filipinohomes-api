<?php

namespace App\Services\Reports\Executive;

use App\Models\Property;
use App\Support\AvatarUrl;
use App\Support\OfficeRegionMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a page of drill ids into display rows with typed cells — the drill
 * drawer renders any metric from `columns` alone, so a new metric needs no
 * frontend change.
 *
 * Cell types: listing, agent, person, team, place, text, number, money,
 * ppsqm, sqm, minutes, duration_hours, date, datetime, status, chat_link.
 * Entity cells carry what the UI links with (ids, slugs, names, photos).
 * Lookups are by primary key, for the page's ids only.
 */
final class RowHydrator
{
    public function __construct(
        private readonly ReportQueries $q,
        private readonly TeamDirectory $teams,
    ) {}

    /** @return array<int, array{key: string, label: string, type: string, sortable?: bool}> */
    public function columns(string $entity, string $key): array
    {
        $def = MetricRegistry::get($key);

        return match ($entity) {
            'listing' => array_values(array_filter([
                $key === 'claims.sold_unmarked' || $key === 'claims.reserved_unmarked'
                    ? ['key' => 'said', 'label' => 'Said on', 'type' => 'datetime', 'sortable' => true] : null,
                ['key' => 'listing', 'label' => 'Listing', 'type' => 'listing'],
                ['key' => 'place', 'label' => 'Place', 'type' => 'place'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ['key' => 'price', 'label' => 'Price', 'type' => 'money', 'sortable' => true],
                $def['kind'] === 'median' ? ['key' => 'area', 'label' => 'Area', 'type' => 'sqm'] : null,
                $def['kind'] === 'median' ? ['key' => 'ppsqm', 'label' => '₱/sqm', 'type' => 'ppsqm'] : null,
                ['key' => 'agent', 'label' => 'Agent', 'type' => 'agent'],
                ['key' => 'added', 'label' => 'Added', 'type' => 'datetime', 'sortable' => true],
            ])),
            'deal' => [
                ['key' => 'listing', 'label' => 'Listing', 'type' => 'listing'],
                ['key' => 'place', 'label' => 'Place', 'type' => 'place'],
                ['key' => 'type', 'label' => 'Type', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Deal', 'type' => 'status'],
                ['key' => 'deal_date', 'label' => 'Deal date', 'type' => 'date', 'sortable' => true],
                ['key' => 'price', 'label' => 'Asking price', 'type' => 'money', 'sortable' => true],
                ['key' => 'agent', 'label' => 'Agent', 'type' => 'agent'],
                ['key' => 'marked_by', 'label' => 'Marked by', 'type' => 'text'],
            ],
            'inquiry' => array_values(array_filter([
                ['key' => 'received', 'label' => 'Received', 'type' => 'datetime', 'sortable' => true],
                ['key' => 'client', 'label' => 'Client', 'type' => 'person'],
                ['key' => 'listing', 'label' => 'Listing', 'type' => 'listing'],
                ['key' => 'agent', 'label' => 'Agent', 'type' => 'agent'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                $def['family'] === 'lifecycle' && $key !== 'inquiries.first_reply_minutes'
                    ? ['key' => 'waiting', 'label' => 'Waiting', 'type' => 'duration_hours', 'sortable' => true] : null,
                $key === 'inquiries.first_reply_minutes'
                    ? ['key' => 'first_reply', 'label' => 'First reply', 'type' => 'minutes'] : null,
                ['key' => 'chat', 'label' => 'Conversation', 'type' => 'chat_link'],
            ])),
            'user' => [
                ['key' => 'person', 'label' => 'Name', 'type' => 'person'],
                ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
                ['key' => 'joined', 'label' => 'Joined', 'type' => 'date', 'sortable' => true],
                ['key' => 'last_online', 'label' => 'Last online', 'type' => 'datetime', 'sortable' => true],
            ],
            'agent' => [
                ['key' => 'agent', 'label' => 'Agent', 'type' => 'agent'],
                ['key' => 'office', 'label' => 'Office region', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ['key' => 'last_online', 'label' => 'Last online', 'type' => 'datetime', 'sortable' => true],
                ['key' => 'joined', 'label' => 'Joined', 'type' => 'date', 'sortable' => true],
            ],
            'team' => [
                ['key' => 'team', 'label' => 'Team', 'type' => 'team'],
                ['key' => 'leaders', 'label' => 'Leader', 'type' => 'text'],
                ['key' => 'members', 'label' => 'Agents', 'type' => 'number', 'metric' => 'agents.headcount'],
                ['key' => 'active', 'label' => 'Active', 'type' => 'number', 'metric' => 'agents.active'],
            ],
        };
    }

    /** Listing or deal rows, in the order of $ids. `$extra[id]` adds per-row facts (e.g. said). */
    public function listings(array $ids, string $entity, string $key, array $extra = []): array
    {
        $details = $this->q->listingDetails($ids);
        $markedBy = $entity === 'deal' ? $this->markedBy($details->pluck('property_id')->filter()->all()) : [];

        $rows = [];
        foreach ($ids as $id) {
            $d = $details->get($id);
            if ($d === null) {
                continue;
            }

            $area = PricePerSqm::area($d->type, $d->floor_area, $d->lot_area);
            $row = [
                'listing' => $this->listingCell($d->id, $d->name, $d->slug, $d->code, $d->featured_photo),
                'place' => $this->place($d->city, $d->province),
                'type' => $d->type ?? 'Unspecified',
                'status' => $d->status,
                'price' => $d->price !== null ? (float) $d->price : null,
                'agent' => $this->agentCell($d->agent_id, $d->agent_first_name, $d->agent_last_name, $d->agent_user_name, $d->agent_avatar, $d->agent_user_avatar),
                'added' => $this->iso($d->created_at),
            ];

            if ($entity === 'deal') {
                $row['deal_date'] = $d->status_change_date ? substr((string) $d->status_change_date, 0, 10) : null;
                $row['marked_by'] = $markedBy[$d->property_id] ?? null;
            }
            if (MetricRegistry::get($key)['kind'] === 'median') {
                $row['area'] = $area > 0 ? $area : null;
                $row['ppsqm'] = $d->price !== null ? PricePerSqm::perSqm((float) $d->price, $d->type, $d->floor_area, $d->lot_area) : null;
            }

            $rows[] = $row + ($extra[$id] ?? []);
        }

        return $rows;
    }

    /**
     * Inquiry rows, in the order of $chatIds. `$timing[chat id]` carries the
     * lifecycle facts (waiting since / first reply) when the drill has them.
     */
    public function inquiries(array $chatIds, array $timing = [], ?Carbon $now = null): array
    {
        $details = $this->q->inquiryDetails($chatIds);
        $now ??= Carbon::now();

        $rows = [];
        foreach ($chatIds as $chatId) {
            $d = $details->get($chatId);
            if ($d === null) {
                continue;
            }

            $row = [
                'received' => $this->iso($d->received_at),
                'client' => ['id' => (int) $d->client_id, 'name' => $d->client_name, 'avatar' => $this->avatar($d->client_avatar)],
                'listing' => $this->listingCell($d->listing_id, $d->listing_name, $d->listing_slug, $d->listing_code, $d->featured_photo),
                'agent' => $this->agentCell($d->agent_id, $d->agent_first_name, $d->agent_last_name, $d->agent_user_name, $d->agent_avatar, $d->agent_user_avatar),
                'status' => $d->conversation_status,
                'chat' => [
                    'chat_id' => (int) $d->chat_id,
                    'type' => 'listing',
                    'listing_name' => $d->listing_name,
                    'client_name' => $d->client_name,
                ],
            ];

            if (isset($timing[$chatId]['waiting_since'])) {
                $since = Carbon::parse($timing[$chatId]['waiting_since'], 'UTC');
                $row['waiting'] = round(max(0, $now->getTimestamp() - $since->getTimestamp()) / 3600, 1);
            }
            if (isset($timing[$chatId]['first_reply'])) {
                $row['first_reply'] = $timing[$chatId]['first_reply'];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** User rows, in the order of $ids. */
    public function users(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $details = DB::table('users as u')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->whereIn('u.id', $ids)
            ->get(['u.id', 'u.name', 'u.avatar', 'u.created_at', 'u.last_online_at', 'r.name as role'])
            ->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            $d = $details->get($id);
            if ($d === null) {
                continue;
            }
            $rows[] = [
                'person' => ['id' => (int) $d->id, 'name' => $d->name, 'avatar' => $this->avatar($d->avatar)],
                'role' => $d->role ? ucfirst($d->role) : null,
                'joined' => $d->created_at ? substr((string) $d->created_at, 0, 10) : null,
                'last_online' => $this->iso($d->last_online_at),
            ];
        }

        return $rows;
    }

    /** Agent rows, in the order of $ids. */
    public function agents(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $details = DB::table('agents')
            ->leftJoin('users as u', 'u.id', '=', 'agents.user_id')
            ->whereIn('agents.id', $ids)
            ->get([
                'agents.id', 'agents.first_name', 'agents.last_name', 'agents.avatar', 'agents.status',
                'agents.region', 'agents.created_at', 'u.name as user_name', 'u.avatar as user_avatar', 'u.last_online_at',
            ])
            ->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            $d = $details->get($id);
            if ($d === null) {
                continue;
            }
            $rows[] = [
                'agent' => $this->agentCell($d->id, $d->first_name, $d->last_name, $d->user_name, $d->avatar, $d->user_avatar),
                'office' => $d->region && OfficeRegionMap::isValid((string) $d->region) ? OfficeRegionMap::label((string) $d->region) : null,
                'status' => $d->status,
                'last_online' => $this->iso($d->last_online_at),
                'joined' => $d->created_at ? substr((string) $d->created_at, 0, 10) : null,
            ];
        }

        return $rows;
    }

    /**
     * Team rows for a page of team ids. Member counts are the drillable
     * agents.headcount / agents.active numbers for that team.
     *
     * @param  array<int, int>  $headcount  team id => roster agents
     * @param  array<int, int>  $active  team id => active agents in the range
     */
    public function teams(array $teamIds, array $headcount, array $active): array
    {
        $leaderIds = [];
        foreach ($teamIds as $teamId) {
            foreach ($this->teams->leaders($teamId) as $agentId) {
                $leaderIds[$agentId] = $teamId;
            }
        }
        $leaderNames = $leaderIds === [] ? collect() : DB::table('agents')
            ->leftJoin('users as u', 'u.id', '=', 'agents.user_id')
            ->whereIn('agents.id', array_keys($leaderIds))
            ->get(['agents.id', 'agents.first_name', 'agents.last_name', 'u.name as user_name'])
            ->keyBy('id');

        $rows = [];
        foreach ($teamIds as $teamId) {
            $leaders = [];
            foreach ($this->teams->leaders($teamId) as $agentId) {
                $l = $leaderNames->get($agentId);
                if ($l !== null) {
                    $leaders[] = $this->agentName($l->first_name, $l->last_name, $l->user_name);
                }
            }
            $rows[] = [
                'team' => ['id' => $teamId, 'name' => $this->teams->name($teamId)],
                'leaders' => $leaders === [] ? null : implode(', ', $leaders),
                'members' => $headcount[$teamId] ?? 0,
                'active' => $active[$teamId] ?? 0,
                'filters' => ['team_id' => $teamId],
            ];
        }

        return $rows;
    }

    // ── Cells ───────────────────────────────────────────────────────────────

    public function agentCell($id, $first, $last, $userName, $avatar, $userAvatar): ?array
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        $name = $this->agentName($first, $last, $userName);
        $teamId = $this->teams->teamOf($id);

        return [
            'id' => $id,
            'name' => $name,
            // The public profile resolves agents by the trailing id, so any
            // name prefix works; this one matches buildAgentSlug() on the web.
            'slug' => (Str::slug($name) !== '' ? Str::slug($name).'-' : '').$id,
            'avatar' => $this->avatar($avatar) ?? $this->avatar($userAvatar),
            'team' => $teamId !== null ? ['id' => $teamId, 'name' => $this->teams->name($teamId)] : null,
        ];
    }

    public function listingCell($id, $name, $slug, $code, $photo): array
    {
        return [
            'id' => (int) $id,
            'name' => $name,
            'slug' => $slug,
            'code' => $code,
            'photo' => $this->firstPhoto($photo),
        ];
    }

    private function agentName($first, $last, $userName): string
    {
        $name = trim(trim((string) $first).' '.trim((string) $last));

        return $name !== '' ? $name : (string) ($userName ?? 'Agent');
    }

    public function place($city, $province): ?string
    {
        $parts = array_values(array_filter([$city, $province], fn ($p) => $p !== null && $p !== ''));

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    /** First non-blank URL of an avatar (JSON array or bare string). */
    private function avatar($value): ?string
    {
        return $this->firstUrl($value, clean: true);
    }

    private function firstPhoto($value): ?string
    {
        return $this->firstUrl($value, clean: false);
    }

    private function firstUrl($value, bool $clean): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && str_starts_with(ltrim($value), '[')) {
            $value = json_decode($value, true);
        }
        if (is_array($value)) {
            $value = collect($value)->first(fn ($v) => is_string($v) && trim($v) !== '');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $clean ? AvatarUrl::clean($value) : $value;
    }

    /** A stored UTC timestamp as an ISO-8601 Manila time (the UI formats it). */
    private function iso($value): ?string
    {
        return $value ? Carbon::parse((string) $value, 'UTC')->setTimezone(ReportRange::TZ)->toIso8601String() : null;
    }

    /**
     * Who last marked each property's status — the latest status-change audit
     * row's actor (only kept since 2026-05-21; null before that).
     *
     * @return array<int, string>
     */
    private function markedBy(array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        return DB::table('audits')
            ->where('auditable_type', Property::class)
            ->whereIn('auditable_id', $propertyIds)
            ->where('source', 'status_change')
            ->orderByDesc('id')
            ->get(['auditable_id', 'user_name'])
            ->unique('auditable_id')
            ->mapWithKeys(fn ($row) => [(int) $row->auditable_id => $row->user_name])
            ->filter()
            ->all();
    }
}
