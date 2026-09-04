<?php

namespace App\Services\Birthday;

use Illuminate\Support\Facades\DB;

/**
 * Who gets a birthday greeting today: ACTIVE agents (agents.status = 'active',
 * not soft-deleted) whose agents.birthdate is today (Asia/Manila), with a
 * reachable address (users.email, else agents.lr_email). The 1970-01-01
 * epoch default is junk, not a birthday. Client-role users are excluded the
 * same way the admin digest excludes them.
 *
 * Display name is first + last only (Title Case — LR data is often ALL CAPS)
 * — the middle name is deliberately left out of both the poster and the email.
 */
class AgentBirthdayGreetingService
{
    /**
     * @param  string  $date  'Y-m-d'
     * @return array<int, array{agent_id:int, user_id:int, first_name:string, last_name:string, full_name:string, email:string, avatar:?string}>
     */
    public function celebrants(string $date): array
    {
        $md = date('m-d', strtotime($date));

        return $this->mapRows($this->baseQuery()->where(DB::raw("DATE_FORMAT(agents.birthdate, '%m-%d')"), $md)->get($this->columns()));
    }

    /**
     * A REAL agent for test sends: today's first celebrant, else the agent
     * with the soonest upcoming birthday (photo-holders first so the sample
     * shows the composited avatar). Null only if no agent has a birthdate.
     *
     * @return array{agent_id:int, user_id:int, first_name:string, last_name:string, full_name:string, email:string, avatar:?string}|null
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
        $days = "((DAYOFYEAR(STR_TO_DATE(CONCAT('2001-', DATE_FORMAT(agents.birthdate, '%m-%d')), '%Y-%m-%d'))
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
        return DB::table('agents')
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNull('agents.deleted_at')
            ->where('agents.status', 'active')
            ->where('roles.name', '!=', 'client')
            ->whereNotNull('agents.birthdate')
            ->where('agents.birthdate', '!=', '1970-01-01')
            ->orderBy('agents.last_name');
    }

    private function columns(): array
    {
        return [
            'agents.id as agent_id', 'agents.user_id', 'agents.first_name', 'agents.last_name',
            'agents.avatar as agent_avatar', 'agents.lr_email',
            'users.email as user_email', 'users.avatar as user_avatar',
        ];
    }

    private function mapRows($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $email = trim((string) ($r->user_email ?: $r->lr_email));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $first = BirthdayPosterService::titleCase((string) $r->first_name);
            $last = BirthdayPosterService::titleCase((string) $r->last_name);
            $out[] = [
                'agent_id' => (int) $r->agent_id,
                'user_id' => (int) $r->user_id,
                'first_name' => $first,
                'last_name' => $last,
                'full_name' => trim("{$first} {$last}"),
                'email' => $email,
                'avatar' => BirthdayPosterService::avatarFor($r->agent_avatar, $r->user_avatar),
            ];
        }

        return $out;
    }
}
