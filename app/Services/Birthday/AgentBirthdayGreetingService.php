<?php

namespace App\Services\Birthday;

use Illuminate\Support\Facades\DB;

/**
 * Who gets a birthday greeting today: every STAFF user — any role but client
 * (admin, agent, editor, secretary) — whose birthday is today (Asia/Manila),
 * with a reachable address (users.email, else agents.lr_email).
 *
 * Birthdate is agents.birthdate (the LR backfill / profile-edit) falling back
 * to users.birthdate, so an admin without an agents row still counts. The
 * agents row is LEFT-joined: only agent-role users are gated on
 * agents.status = 'active' — an admin stays an admin whatever their agent
 * record says. The 1970-01-01 epoch default is junk, not a birthday.
 *
 * Display name is first + last only (Title Case — LR data is often ALL CAPS)
 * — the middle name is deliberately left out of both the poster and the email.
 */
class AgentBirthdayGreetingService
{
    /**
     * @param  string  $date  'Y-m-d'
     * @return array<int, array{agent_id:?int, user_id:int, poster_key:string, first_name:string, last_name:string, full_name:string, email:string, avatar:?string}>
     */
    public function celebrants(string $date): array
    {
        $md = date('m-d', strtotime($date));

        return $this->mapRows($this->baseQuery()->where(DB::raw('DATE_FORMAT('.self::BIRTHDATE.", '%m-%d')"), $md)->get($this->columns()));
    }

    /** The birthdate column expression: agent row first, then the user row. */
    public const BIRTHDATE = 'COALESCE(agents.birthdate, users.birthdate)';

    /**
     * A REAL agent for test sends: today's first celebrant, else the agent
     * with the soonest upcoming birthday (photo-holders first so the sample
     * shows the composited avatar). Null only if no agent has a birthdate.
     *
     * @return array{agent_id:?int, user_id:int, poster_key:string, first_name:string, last_name:string, full_name:string, email:string, avatar:?string}|null
     */
    public function sampleCelebrant(string $date, ?int $agentId = null): ?array
    {
        if ($agentId) {
            $rows = $this->mapRows($this->baseQuery()->where('agents.id', $agentId)->get($this->columns()));

            return $rows[0] ?? null;
        }

        $today = $this->celebrants($date);
        if ($today) {
            return $today[0];
        }

        // Days until next birthday, wrapping the year — smallest first.
        $md = date('m-d', strtotime($date));
        $bd = self::BIRTHDATE;
        $days = "((DAYOFYEAR(STR_TO_DATE(CONCAT('2001-', DATE_FORMAT({$bd}, '%m-%d')), '%Y-%m-%d'))
                  - DAYOFYEAR(STR_TO_DATE('2001-{$md}', '%Y-%m-%d')) + 365) % 365)";

        $rows = $this->mapRows(
            $this->baseQuery()
                ->reorder()
                ->orderByRaw("{$days} ASC")
                ->limit(40)
                ->get($this->columns())
        );

        // Prefer someone with a usable photo and a real-looking name so the
        // sample actually exercises the composited avatar.
        foreach ($rows as $r) {
            if ($r['avatar'] && mb_strlen($r['full_name']) >= 5) {
                return $r;
            }
        }

        return $rows[0] ?? null;
    }

    private function baseQuery()
    {
        $bd = self::BIRTHDATE;

        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->leftJoin('agents', function ($j) {
                $j->on('agents.user_id', '=', 'users.id')->whereNull('agents.deleted_at');
            })
            ->where('roles.name', '!=', 'client')
            // Agents must be active; every other staff role passes as-is
            // (with or without an agents row).
            ->where(function ($w) {
                $w->where('roles.name', '!=', 'agent')->orWhere('agents.status', 'active');
            })
            ->whereRaw("{$bd} IS NOT NULL")
            ->whereRaw("{$bd} != '1970-01-01'")
            ->orderByRaw('COALESCE(agents.last_name, users.name)');
    }

    private function columns(): array
    {
        return [
            'agents.id as agent_id', 'users.id as user_id', 'users.name as user_name', 'roles.name as role',
            'agents.first_name', 'agents.last_name',
            'agents.avatar as agent_avatar', 'agents.lr_email',
            'users.email as user_email', 'users.avatar as user_avatar',
        ];
    }

    private function mapRows($rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            // A user can own more than one agents row; one greeting per person.
            if (isset($seen[$r->user_id])) {
                continue;
            }
            $email = trim((string) ($r->user_email ?: $r->lr_email));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            [$first, $last] = self::splitName($r->first_name, $r->last_name, $r->user_name);
            $seen[$r->user_id] = true;
            $out[] = [
                'agent_id' => $r->agent_id !== null ? (int) $r->agent_id : null,
                'user_id' => (int) $r->user_id,
                // Stable per-person id for the day's poster + send marker —
                // agent ids and user ids are separate sequences, so prefix.
                'poster_key' => $r->agent_id !== null ? 'agent-'.(int) $r->agent_id : 'user-'.(int) $r->user_id,
                'first_name' => $first,
                'last_name' => $last,
                'full_name' => trim("{$first} {$last}"),
                'email' => $email,
                'avatar' => BirthdayPosterService::avatarFor($r->agent_avatar, $r->user_avatar),
            ];
        }

        return $out;
    }

    /**
     * First + last for the poster/greeting: the agents row when present, else
     * users.name split on its last space (an admin without an agents row).
     *
     * @return array{0:string,1:string}
     */
    public static function splitName(?string $first, ?string $last, ?string $userName): array
    {
        $first = trim((string) $first);
        $last = trim((string) $last);
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) $userName)) ?: [];
            $last = count($parts) > 1 ? (string) array_pop($parts) : '';
            $first = implode(' ', $parts);
        }

        return [BirthdayPosterService::titleCase($first), BirthdayPosterService::titleCase($last)];
    }
}
