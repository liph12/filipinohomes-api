<?php

namespace App\Services\Audience;

use Illuminate\Support\Facades\DB;

/**
 * Powers the front-end AudienceGeography card: the Top-10 country / state / city
 * breakdown, geo resolved by ipinfo.io. Combines two non-overlapping sources so
 * the count is unique per person/device with no double counting, scoped to the
 * instance date range:
 *   - clients  : their stored user_info location, by registration date.
 *   - visitors : anonymous visits only (user_id IS NULL), DISTINCT device, by
 *                visit date. Logged-in clients' visits are skipped here because
 *                they're already counted via user_info.
 */
class AudienceGeographyService extends AudienceInsightsService
{
    public function breakdown(): array
    {
        // Unique clients by stored location (+ their country, for the flag),
        // scoped to registrations in range.
        $clientGeo = function (string $column) {
            return DB::table('user_info')
                ->join('users', 'users.id', '=', 'user_info.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', 'client')
                ->whereBetween('users.created_at', [$this->startDt, $this->endDt])
                ->whereNotNull('user_info.' . $column)
                ->where('user_info.' . $column, '!=', '')
                ->groupBy('user_info.' . $column, 'user_info.country')
                ->select('user_info.' . $column . ' as name', 'user_info.country as country', DB::raw('COUNT(DISTINCT user_info.user_id) as c'))
                ->get();
        };

        // Unique anonymous visitor devices in range (+ their country).
        $visitorGeo = function (string $column) {
            return DB::table('visits')
                ->whereBetween('created_at', [$this->startDt, $this->endDt])
                ->whereNull('user_id')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column, 'country')
                ->select($column . ' as name', 'country as country', DB::raw('COUNT(DISTINCT visitor_id) as c'))
                ->get();
        };

        // Merge both sources (same ipinfo vocabulary): sum per name, pick the
        // dominant country for the flag, take the top 10.
        $merge = function ($clients, $visitors): array {
            $totals = [];
            $countryVotes = [];
            foreach ([$clients, $visitors] as $rows) {
                foreach ($rows as $r) {
                    $name = trim((string) $r->name);
                    if ($name === '' || strcasecmp($name, 'unknown') === 0) {
                        continue;
                    }
                    $count = (int) $r->c;
                    $totals[$name] = ($totals[$name] ?? 0) + $count;
                    $ct = trim((string) ($r->country ?? ''));
                    if ($ct !== '' && strcasecmp($ct, 'unknown') !== 0) {
                        $countryVotes[$name][$ct] = ($countryVotes[$name][$ct] ?? 0) + $count;
                    }
                }
            }
            arsort($totals);
            $totals = array_slice($totals, 0, 10, true);

            return array_map(function ($name, $count) use ($countryVotes) {
                $country = null;
                if (!empty($countryVotes[$name])) {
                    arsort($countryVotes[$name]);
                    $country = array_key_first($countryVotes[$name]);
                }
                return ['name' => $name, 'value' => $count, 'country' => $country];
            }, array_keys($totals), array_values($totals));
        };

        return [
            'countries' => $merge($clientGeo('country'), $visitorGeo('country')),
            'states'    => $merge($clientGeo('state'),   $visitorGeo('region')),
            'cities'    => $merge($clientGeo('city'),    $visitorGeo('city')),
        ];
    }
}
