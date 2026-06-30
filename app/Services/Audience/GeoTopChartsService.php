<?php

namespace App\Services\Audience;

use Illuminate\Support\Facades\DB;

/**
 * Powers the front-end GeoTopCharts card: per-day geography series for the
 * Top-10 line charts. For each dimension (country / region / city) returns the
 * top-10 locations by total and their daily counts — client registrations (by
 * users.created_at) + anonymous visits (by visit date), matching the
 * AudienceGeography breakdown totals. Scoped to the instance date range.
 */
class GeoTopChartsService extends AudienceInsightsService
{
    /**
     * @param string|null $country When set (ISO code), the region & city series are
     *   scoped to that country's top 10 (drill-down). The country series stays global.
     */
    public function build(?string $country = null): array
    {
        // Build each dimension's pivot (byName[date] + totals) without an axis yet.
        // $scopeCountry, when set, narrows both sources to that country (region/city
        // drill-down) — mirrors AudienceGeographyService::breakdown().
        $dim = function (string $userCol, string $visitCol, ?string $scopeCountry = null) {
            $byName = [];
            $totals = [];
            $add = function ($rows) use (&$byName, &$totals) {
                foreach ($rows as $r) {
                    $name = trim((string) $r->name);
                    if ($name === '' || strcasecmp($name, 'unknown') === 0) {
                        continue;
                    }
                    $byName[$name][$r->d] = ($byName[$name][$r->d] ?? 0) + (int) $r->c;
                    $totals[$name] = ($totals[$name] ?? 0) + (int) $r->c;
                }
            };

            $clientQ = DB::table('user_info')
                ->join('users', 'users.id', '=', 'user_info.user_id')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', 'client')
                ->whereBetween('users.created_at', [$this->startDt, $this->endDt])
                ->whereNotNull('user_info.' . $userCol)
                ->where('user_info.' . $userCol, '!=', '');
            if ($scopeCountry !== null && $scopeCountry !== '') {
                $clientQ->where('user_info.country', $scopeCountry);
            }
            $add($clientQ
                ->groupBy(DB::raw('DATE(users.created_at)'), 'user_info.' . $userCol)
                ->select(DB::raw('DATE(users.created_at) as d'), 'user_info.' . $userCol . ' as name', DB::raw('COUNT(DISTINCT user_info.user_id) as c'))
                ->get());

            $visitQ = DB::table('visits')
                ->whereBetween('created_at', [$this->startDt, $this->endDt])
                ->whereNull('user_id')
                ->whereNotNull($visitCol)
                ->where($visitCol, '!=', '');
            if ($scopeCountry !== null && $scopeCountry !== '') {
                $visitQ->where('country', $scopeCountry);
            }
            $add($visitQ
                ->groupBy(DB::raw('DATE(created_at)'), $visitCol)
                ->select(DB::raw('DATE(created_at) as d'), $visitCol . ' as name', DB::raw('COUNT(DISTINCT visitor_id) as c'))
                ->get());

            return ['byName' => $byName, 'totals' => $totals];
        };

        // Countries stay global; regions & cities drill into the selected country.
        $countryDim = $dim('country', 'country');
        $regionDim  = $dim('state', 'region', $country);
        $cityDim    = $dim('city', 'city', $country);

        // Shared axis starting at the first day with data across all dimensions.
        $allKeys = [];
        foreach ([$countryDim, $regionDim, $cityDim] as $d) {
            foreach ($d['byName'] as $m) {
                $allKeys = array_merge($allKeys, array_keys($m));
            }
        }
        $dates = $this->buildDates($allKeys ? min($allKeys) : null);

        $top = function (array $d) use ($dates) {
            arsort($d['totals']);
            $names = array_slice(array_keys($d['totals']), 0, 10);
            return array_map(fn ($name) => [
                'name' => $name,
                'data' => array_map(fn ($dt) => $d['byName'][$name][$dt] ?? 0, $dates),
            ], $names);
        };

        return [
            'dates'   => $dates,
            'country' => $top($countryDim),
            'region'  => $top($regionDim),
            'city'    => $top($cityDim),
        ];
    }
}
