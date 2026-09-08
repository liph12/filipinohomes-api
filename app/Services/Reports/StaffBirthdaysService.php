<?php

namespace App\Services\Reports;

use App\Services\Birthday\AgentBirthdayGreetingService;
use App\Services\Birthday\BirthdayPosterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff birthdays for the daily birthday email: today's plus the next 30
 * days. Everyone but the client role — admins, agents, editors, secretaries.
 * Birthdate is agents.birthdate (the LR backfill / profile-edit) falling back
 * to users.birthdate, with the agents row LEFT-joined so an admin without one
 * still appears. The 1970-01-01 epoch default is junk data, not a birthday.
 *
 * The upcoming list is capped at 10 — unless today + tomorrow alone exceed
 * 10, in which case it shows everything up to tomorrow (and only that far).
 * `upcoming_total` carries the real 30-day count for the "+N more" line.
 */
class StaffBirthdaysService
{
    /**
     * @param  string  $date  'Y-m-d' — the day that counts as "today".
     */
    public function build(string $date): array
    {
        $ref = Carbon::parse($date);
        $mdKeys = [];
        for ($i = 0; $i <= 30; $i++) {
            $mdKeys[] = $ref->copy()->addDays($i)->format('m-d');
        }

        $bd = AgentBirthdayGreetingService::BIRTHDATE;
        $rows = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->leftJoin('agents', function ($j) {
                $j->on('agents.user_id', '=', 'users.id')->whereNull('agents.deleted_at');
            })
            ->where('roles.name', '!=', 'client')
            ->whereRaw("{$bd} IS NOT NULL")
            ->whereRaw("{$bd} != '1970-01-01'")
            ->whereIn(DB::raw("DATE_FORMAT({$bd}, '%m-%d')"), $mdKeys)
            ->get([
                'users.id as user_id', 'users.name', DB::raw("{$bd} as birthdate"), 'agents.id as agent_id',
                'agents.first_name', 'agents.last_name',
                'agents.avatar as agent_avatar', 'users.avatar as user_avatar',
            ]);

        $today = [];
        $upcoming = [];
        $seen = [];
        foreach ($rows as $row) {
            // One line per person even if they own several agents rows.
            if (isset($seen[$row->user_id])) {
                continue;
            }
            $seen[$row->user_id] = true;
            $md = Carbon::parse($row->birthdate)->format('m-d');
            $offset = array_search($md, $mdKeys, true);
            if ($offset === false) {
                continue;
            }
            $entry = [
                'name' => (string) $row->name,
                'date' => $ref->copy()->addDays($offset)->format('M j'),
                'offset' => (int) $offset,
            ];
            if ($offset === 0) {
                // Extra fields so the digest can render/attach today's posters
                // (same name + avatar rules as the agent's own greeting).
                [$first, $last] = AgentBirthdayGreetingService::splitName($row->first_name, $row->last_name, $row->name);
                $entry['agent_id'] = $row->agent_id !== null ? (int) $row->agent_id : null;
                $entry['poster_key'] = $row->agent_id !== null ? 'agent-'.(int) $row->agent_id : 'user-'.(int) $row->user_id;
                $entry['poster_name'] = trim("{$first} {$last}");
                $entry['avatar'] = BirthdayPosterService::avatarFor($row->agent_avatar, $row->user_avatar);
                $today[] = $entry;
            } else {
                $upcoming[] = $entry;
            }
        }
        usort($today, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($upcoming, fn ($a, $b) => $a['offset'] <=> $b['offset'] ?: strcasecmp($a['name'], $b['name']));

        $upcomingTotal = count($upcoming);
        $tomorrowCount = count(array_filter($upcoming, fn ($b) => $b['offset'] === 1));
        if (count($today) + $tomorrowCount > 10) {
            $upcoming = array_values(array_filter($upcoming, fn ($b) => $b['offset'] === 1));
        } else {
            $upcoming = array_slice($upcoming, 0, 10);
        }

        return [
            'today' => $today,
            'upcoming' => $upcoming,
            'upcoming_total' => $upcomingTotal,
        ];
    }
}
