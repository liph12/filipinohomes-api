<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff birthdays for the daily birthday email: today's plus the next 30
 * days. Source is agents.birthdate (the LR backfill) joined to users — the
 * client role is excluded so only staff appear (agents are staff by
 * definition; the join guards against an agent row whose user was later
 * demoted to client). The 1970-01-01 epoch default is junk data, not a
 * birthday.
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

        $rows = DB::table('agents')
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', '!=', 'client')
            ->whereNotNull('agents.birthdate')
            ->where('agents.birthdate', '!=', '1970-01-01')
            ->whereIn(DB::raw("DATE_FORMAT(agents.birthdate, '%m-%d')"), $mdKeys)
            ->get(['users.name', 'agents.birthdate']);

        $today = [];
        $upcoming = [];
        foreach ($rows as $row) {
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
