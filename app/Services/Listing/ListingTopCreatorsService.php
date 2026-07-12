<?php

namespace App\Services\Listing;

use App\Support\OfficeRegionMap;
use App\Support\RegionMap;
use Illuminate\Support\Facades\DB;

/**
 * "Top Listing Creators" — ranks agents, teams, cities or office regions by
 * the number of listings created in the date window. Powers the admin rewards
 * dashboard tile, so it is always unscoped (admin-gated in the controller;
 * team leaders are NOT admitted).
 */
class ListingTopCreatorsService extends ListingInsightsService
{
    /**
     * Ranked breakdown for one group_by dimension. Rows are sorted by
     * listing_count DESC and capped at $limit; meta carries the ungrouped
     * total_listings and the pre-limit distinct group count.
     *
     * $cityId / $teamId are the optional drill-downs behind the tile's
     * "click a city/team → see all its creators" views (used with
     * group_by=agent). Both ride on scoping the inherited baseListingQuery —
     * $cityId via the parent's own city filter (COALESCE(projects.city_id,
     * property_cities.id)), $teamId by resolving the team's ACTIVE members
     * into $this->agentIds — so every grouping AND total_listings share the
     * scope, keeping the drill-down's share bars summing to 100%.
     */
    /** When true, only audit-passed AND ATS-approved listings count. */
    private bool $qualifiedOnly = false;

    public function topCreators(string $groupBy, ?string $dateStart = null, ?string $dateEnd = null, int $limit = 20, ?int $cityId = null, ?int $teamId = null, ?int $agentId = null, bool $qualifiedOnly = false): array
    {
        $this->qualifiedOnly = $qualifiedOnly;
        $this->agentIds = null;
        $this->dateStart = $dateStart;
        $this->dateEnd = $dateEnd;
        $this->cityId = $cityId;

        if ($teamId !== null) {
            // Empty membership → whereIn on [] → zero rows, correctly.
            $this->agentIds = DB::table('team_agents')
                ->where('team_id', $teamId)
                ->where('status', 'active')
                ->pluck('agent_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // Agent drill-down (group_by=listing): scope to one agent's listings.
        // Wins over team_id if both are somehow sent.
        if ($agentId !== null) {
            $this->agentIds = [$agentId];
        }

        // One COUNT over the base-filtered range — independent of grouping, so
        // the tile can show "top N of X listings" even when groups are capped.
        $totalListings = (int) $this->scopedQuery()->count();

        [$data, $totalGroups] = match ($groupBy) {
            'team' => $this->byTeam($limit),
            'city' => $this->byCity($limit),
            'office_region' => $this->byOfficeRegion($limit),
            'listing' => $this->byListing($limit),
            default => $this->byAgent($limit),
        };

        return [
            'data' => $data,
            'meta' => [
                'group_by' => $groupBy,
                'date_start' => $this->dateStart,
                'date_end' => $this->dateEnd,
                'limit' => $limit,
                'city_id' => $this->cityId,
                'team_id' => $teamId,
                'agent_id' => $agentId,
                'qualified' => $qualifiedOnly,
                'total_listings' => $totalListings,
                // Verified+ATS-approved total across the range — the Verified
                // column/KPI is ALWAYS shown; the toggle only changes ranking.
                'verified_listings' => (int) $this->baseListingQuery()
                    ->whereIn('listings.verification_status', ['verified', 'fully_verified'])
                    ->where('properties.ats_status', 'approve')
                    ->count(),
                'total_groups' => $totalGroups,
            ],
        ];
    }

    /**
     * baseListingQuery plus the optional quality gate: only listings that
     * passed audit (verified / fully_verified) AND hold an approved
     * authority-to-sell. Every ranking query routes through here so the
     * gate can never be half-applied.
     */
    // Ranking scope is NEVER filtered by verification now: every row carries
    // both a total `listing_count` and a `verified_count`, and only the
    // ORDER BY switches when the caller toggles "rank by verified". Kept as a
    // thin alias so existing call sites read clearly.
    private function scopedQuery()
    {
        return $this->baseListingQuery();
    }

    // COUNT(DISTINCT ...) of listings that passed audit AND are ATS-approved,
    // safe against join fan-out (team grouping). Emits alias `verified_count`.
    private function verifiedCountSql(): string
    {
        return "COUNT(DISTINCT CASE WHEN listings.verification_status IN ('verified', 'fully_verified')"
            ." AND properties.ats_status = 'approve' THEN listings.id END) as verified_count";
    }

    // COUNT(DISTINCT ...) of listings the auditor FLAGGED, safe against join
    // fan-out. Lower is better — a tie-break after verified_count. Alias
    // `flagged_count`.
    private function flaggedCountSql(): string
    {
        return "COUNT(DISTINCT CASE WHEN listings.verification_status = 'flagged' THEN listings.id END) as flagged_count";
    }

    // Which metric the leaderboard orders by: verified when toggled, else total.
    private function rankColumn(): string
    {
        return $this->qualifiedOnly ? 'verified_count' : 'listing_count';
    }

    /**
     * Tie-breaker for agents on an EQUAL listing/verified count: whoever
     * answers inquiries faster ranks higher. "Fast" is the precomputed
     * agents.median_first_response_seconds — median seconds from
     * conversations.reviewed_at (the moment a moderator/team-leader ACCEPTS an
     * inquiry, after the admin/TL-first review) to that agent's first reply,
     * recomputed hourly by agents:recompute-response-metrics.
     *
     * Mirrors AgentController's `response_speed` sort exactly: only agents
     * with a meaningful sample (>= 3) and an acceptable unanswered rate
     * (< 50%) count as "ranked responders"; everyone else sinks below them and
     * falls through to the name sort, so an agent with little/no inquiry
     * history never leapfrogs a proven fast responder on the tie-break alone.
     *
     * Wrapped in MIN() because byAgent groups by agent — the metric is
     * constant per agent, so MIN() simply surfaces it under ONLY_FULL_GROUP_BY.
     */
    private function agentResponderQualifiedSql(): string
    {
        return 'MIN(agents.response_sample_size) >= 3'
            .' AND MIN(agents.median_first_response_seconds) IS NOT NULL'
            .' AND (MIN(agents.unanswered_response_pct) IS NULL OR MIN(agents.unanswered_response_pct) < 50)';
    }

    /**
     * Team counterpart of the agent tie-breaker: the AVERAGE first-response
     * median across the team's ACTIVE, qualified members (same >= 3 sample /
     * < 50% unanswered gate). A correlated subquery over team_agents so each
     * member is counted once, independent of the listing-row fan-out in the
     * main aggregate. Returns NULL when a team has no qualified responders, so
     * those teams fall through to the name sort. Referenced by its
     * `median_response_seconds` alias in ORDER BY (distinct from the real
     * column name, so no alias/column ambiguity).
     */
    private function teamResponseMedianSql(): string
    {
        return '(SELECT AVG(a2.median_first_response_seconds) FROM team_agents ta2'
            .' JOIN agents a2 ON a2.id = ta2.agent_id AND a2.deleted_at IS NULL'
            ." WHERE ta2.team_id = teams.id AND ta2.status = 'active'"
            .' AND a2.response_sample_size >= 3 AND a2.median_first_response_seconds IS NOT NULL'
            .' AND (a2.unanswered_response_pct IS NULL OR a2.unanswered_response_pct < 50))';
    }

    /**
     * Team counterpart of the agent unanswered-count tie-breaker: the SUM of
     * each active member's unanswered inquiries (stored rate × sample), via a
     * correlated subquery over team_agents so members aren't fanned out by the
     * listing-row join. NULL when no member has a response sample. Referenced
     * by its `unanswered_count` alias in ORDER BY.
     */
    private function teamUnansweredCountSql(): string
    {
        return '(SELECT ROUND(SUM(a2.unanswered_response_pct * a2.response_sample_size / 100)) FROM team_agents ta2'
            .' JOIN agents a2 ON a2.id = ta2.agent_id AND a2.deleted_at IS NULL'
            ." WHERE ta2.team_id = teams.id AND ta2.status = 'active'"
            .' AND a2.unanswered_response_pct IS NOT NULL AND a2.response_sample_size IS NOT NULL)';
    }

    /**
     * Per-agent ranking. The first ACTIVE team of each returned agent is
     * resolved in a second small query (mirrors AgentResource, which exposes
     * teamMembers->first() as {id,name}).
     *
     * @return array{0: array, 1: int}
     */
    private function byAgent(int $limit): array
    {
        $rows = $this->scopedQuery()
            ->join('agents', function ($join) {
                $join->on('agents.id', '=', 'listings.agent_id')
                    ->whereNull('agents.deleted_at');
            })
            ->select(
                'listings.agent_id',
                DB::raw("CONCAT_WS(' ', agents.first_name, agents.last_name) as full_name"),
                'agents.avatar',
                'agents.region',
                DB::raw('COUNT(listings.id) as listing_count'),
                DB::raw($this->verifiedCountSql()),
                DB::raw($this->flaggedCountSql()),
                // Response-speed tie-breaker input (constant per agent, so
                // MIN() just surfaces the value under the GROUP BY). Gated by
                // the same qualified predicate that drives the ORDER BY, so the
                // exposed value is exactly what the tie-break uses — an agent
                // with too small a sample reports null, not a misleading time.
                DB::raw('CASE WHEN '.$this->agentResponderQualifiedSql().' THEN MIN(agents.median_first_response_seconds) ELSE NULL END as median_response_seconds'),
                DB::raw('MIN(agents.response_sample_size) as response_sample_size'),
                // Count of the agent's UNANSWERED inquiries (derived from the
                // stored rate × sample). Lower = better; used as the next
                // tie-break after response speed and surfaced in the UI.
                DB::raw('CASE WHEN MIN(agents.unanswered_response_pct) IS NULL THEN NULL ELSE ROUND(MIN(agents.unanswered_response_pct) * MIN(agents.response_sample_size) / 100) END as unanswered_count')
            )
            ->groupBy('listings.agent_id', 'agents.first_name', 'agents.last_name', 'agents.avatar', 'agents.region')
            ->orderByDesc($this->rankColumn())
            // Tie-break equal counts by inquiry response speed: qualified
            // responders first (0), then fastest median; unqualified (1) fall
            // through to the next tie-breaks.
            ->orderByRaw('CASE WHEN '.$this->agentResponderQualifiedSql().' THEN 0 ELSE 1 END ASC')
            ->orderByRaw('CASE WHEN '.$this->agentResponderQualifiedSql().' THEN MIN(agents.median_first_response_seconds) ELSE NULL END ASC')
            // Then fewer unanswered inquiries ranks higher; agents with no
            // response data (null) sink below those with a real count.
            ->orderByRaw('unanswered_count IS NULL, unanswered_count ASC')
            // Non-inquiry tie-breaks (work even when an agent has no inquiries):
            // most verified listings first, then fewest flagged.
            ->orderByDesc('verified_count')
            ->orderBy('flagged_count')
            ->orderBy('full_name')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->scopedQuery()
            ->join('agents', function ($join) {
                $join->on('agents.id', '=', 'listings.agent_id')
                    ->whereNull('agents.deleted_at');
            })
            ->count(DB::raw('DISTINCT listings.agent_id'));

        // First active team per returned agent — keep the lowest team_agents.id
        // per agent so ties resolve the same way AgentResource's first() does.
        $teams = [];
        $agentIds = $rows->pluck('agent_id')->map(fn ($id) => (int) $id)->all();
        if ($agentIds !== []) {
            $memberships = DB::table('team_agents')
                ->join('teams', 'teams.id', '=', 'team_agents.team_id')
                ->whereIn('team_agents.agent_id', $agentIds)
                ->where('team_agents.status', 'active')
                ->orderBy('team_agents.id')
                ->select('team_agents.agent_id', 'teams.id as team_id', 'teams.name as team_name')
                ->get();
            foreach ($memberships as $membership) {
                $agentId = (int) $membership->agent_id;
                if (! isset($teams[$agentId])) {
                    $teams[$agentId] = [
                        'id' => (int) $membership->team_id,
                        'name' => (string) $membership->team_name,
                    ];
                }
            }
        }

        $data = $rows->map(fn ($row) => [
            'agent_id' => (int) $row->agent_id,
            'full_name' => (string) $row->full_name,
            'avatar' => $this->normalizeAvatar($row->avatar),
            'region' => $row->region !== null ? (string) $row->region : null,
            'team' => $teams[(int) $row->agent_id] ?? null,
            'listing_count' => (int) $row->listing_count,
            'verified_count' => (int) $row->verified_count,
            'flagged_count' => (int) $row->flagged_count,
            // Median inquiry response time (seconds from accept → first reply)
            // that breaks equal-count ties; null when the agent has no
            // qualifying sample. response_sample_size lets the UI decide
            // whether it is meaningful enough to show.
            'median_response_seconds' => $row->median_response_seconds !== null ? (int) round((float) $row->median_response_seconds) : null,
            'response_sample_size' => (int) $row->response_sample_size,
            // Unanswered inquiry count (null when the agent has no response
            // sample). Lower ranks higher; shown in the "Unanswered" column.
            'unanswered_count' => $row->unanswered_count !== null ? (int) $row->unanswered_count : null,
        ])->all();

        return [$data, $totalGroups];
    }

    /**
     * Per-team ranking via active team_agents memberships. listing_count is
     * COUNT(DISTINCT listings.id) — an agent can sit on multiple teams, and a
     * listing must never double count within one team. Leaders are resolved in
     * a second small query over the returned team ids.
     *
     * @return array{0: array, 1: int}
     */
    private function byTeam(int $limit): array
    {
        // The agents join (deleted_at IS NULL) keeps this grouping consistent
        // with byAgent/byOfficeRegion — a soft-deleted agent's listings should
        // not keep counting toward their team.
        $rows = $this->scopedQuery()
            ->join('agents', function ($join) {
                $join->on('agents.id', '=', 'listings.agent_id')
                    ->whereNull('agents.deleted_at');
            })
            ->join('team_agents as ta', function ($join) {
                $join->on('ta.agent_id', '=', 'listings.agent_id')
                    ->where('ta.status', 'active');
            })
            ->join('teams', 'teams.id', '=', 'ta.team_id')
            ->select(
                'teams.id as team_id',
                'teams.name as team_name',
                'teams.logo',
                DB::raw('COUNT(DISTINCT listings.id) as listing_count'),
                DB::raw($this->verifiedCountSql()),
                DB::raw($this->flaggedCountSql()),
                DB::raw('COUNT(DISTINCT listings.agent_id) as agents_count'),
                // Team response-speed tie-breaker input — avg member median.
                DB::raw($this->teamResponseMedianSql().' as median_response_seconds'),
                // Total UNANSWERED inquiries across the team's active members
                // (each member's stored rate × sample). Lower = better.
                DB::raw($this->teamUnansweredCountSql().' as unanswered_count')
            )
            ->groupBy('teams.id', 'teams.name', 'teams.logo')
            ->orderByDesc($this->rankColumn())
            // Tie-break equal counts by the team's average member response
            // speed: teams with qualified responders first (0) ordered fastest
            // first; teams with none (1) fall through to the next tie-breaks.
            ->orderByRaw('CASE WHEN median_response_seconds IS NOT NULL THEN 0 ELSE 1 END ASC')
            ->orderByRaw('median_response_seconds ASC')
            // Then fewer total unanswered inquiries ranks higher.
            ->orderByRaw('unanswered_count IS NULL, unanswered_count ASC')
            // Non-inquiry tie-breaks: most verified listings, then fewest flagged.
            ->orderByDesc('verified_count')
            ->orderBy('flagged_count')
            ->orderBy('team_name')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->scopedQuery()
            ->join('agents', function ($join) {
                $join->on('agents.id', '=', 'listings.agent_id')
                    ->whereNull('agents.deleted_at');
            })
            ->join('team_agents as ta', function ($join) {
                $join->on('ta.agent_id', '=', 'listings.agent_id')
                    ->where('ta.status', 'active');
            })
            ->join('teams', 'teams.id', '=', 'ta.team_id')
            ->count(DB::raw('DISTINCT teams.id'));

        // Leader name per returned team (team_agents.is_leader). Keep the first
        // row per team in case of duplicate leader flags.
        $leaders = [];
        $teamIds = $rows->pluck('team_id')->map(fn ($id) => (int) $id)->all();
        if ($teamIds !== []) {
            $leaderRows = DB::table('team_agents')
                ->join('agents', 'agents.id', '=', 'team_agents.agent_id')
                ->whereIn('team_agents.team_id', $teamIds)
                ->where('team_agents.is_leader', 1)
                ->whereNull('agents.deleted_at')
                ->orderBy('team_agents.id')
                ->select('team_agents.team_id', DB::raw("CONCAT_WS(' ', agents.first_name, agents.last_name) as leader_name"))
                ->get();
            foreach ($leaderRows as $leaderRow) {
                $teamId = (int) $leaderRow->team_id;
                if (! isset($leaders[$teamId])) {
                    $leaders[$teamId] = (string) $leaderRow->leader_name;
                }
            }
        }

        $data = $rows->map(fn ($row) => [
            'team_id' => (int) $row->team_id,
            'team_name' => (string) $row->team_name,
            'logo' => $row->logo !== null && $row->logo !== '' ? (string) $row->logo : null,
            'leader_name' => $leaders[(int) $row->team_id] ?? null,
            'agents_count' => (int) $row->agents_count,
            'listing_count' => (int) $row->listing_count,
            'verified_count' => (int) $row->verified_count,
            'flagged_count' => (int) $row->flagged_count,
            // Avg member inquiry response time (seconds) that breaks equal-count
            // ties; null when no team member has a qualifying sample.
            'median_response_seconds' => $row->median_response_seconds !== null ? (int) round((float) $row->median_response_seconds) : null,
            // Total unanswered inquiries across active members (null when no
            // member has a response sample). Lower ranks higher.
            'unanswered_count' => $row->unanswered_count !== null ? (int) $row->unanswered_count : null,
        ])->all();

        return [$data, $totalGroups];
    }

    /**
     * Per-city ranking over the canonical location resolution (project city
     * wins over property → barangay city). Listings that resolve to no city
     * are excluded rather than lumped into a null bucket.
     *
     * Grouping is by the effective city id ONLY — including the coalesced
     * name/province expressions in the group key can split one city into
     * multiple rows when projects.prov_id disagrees with the barangay chain
     * (seen with Davao City). Canonical names come from cities/provinces in a
     * follow-up lookup instead.
     *
     * Each city row also carries its top agent and top team (the admin rewards
     * view needs to see WHO drives each city), resolved in two follow-up
     * queries scoped to the returned city ids.
     *
     * @return array{0: array, 1: int}
     */
    private function byCity(int $limit): array
    {
        $cityIdExpr = 'COALESCE(projects.city_id, property_cities.id)';

        $rows = $this->scopedQuery()
            ->whereNotNull(DB::raw($cityIdExpr))
            ->select(
                DB::raw("{$cityIdExpr} as city_id"),
                DB::raw('COUNT(listings.id) as listing_count'),
                DB::raw($this->verifiedCountSql()),
                DB::raw('COUNT(DISTINCT listings.agent_id) as agents_count')
            )
            ->groupByRaw($cityIdExpr)
            ->orderByDesc($this->rankColumn())
            ->orderBy('city_id')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->scopedQuery()
            ->whereNotNull(DB::raw($cityIdExpr))
            ->count(DB::raw("DISTINCT {$cityIdExpr}"));

        $cityIds = $rows->pluck('city_id')->map(fn ($id) => (int) $id)->all();

        // Canonical city + province names (one row per city id, so the split
        // that the coalesced-name grouping allowed can't happen here).
        $cityMeta = [];
        if ($cityIds !== []) {
            $metaRows = DB::table('cities')
                ->leftJoin('provinces', 'provinces.id', '=', 'cities.province_id')
                ->whereIn('cities.id', $cityIds)
                ->select('cities.id', 'cities.name as city_name', 'provinces.name as province_name')
                ->get();
            foreach ($metaRows as $metaRow) {
                $cityMeta[(int) $metaRow->id] = $metaRow;
            }
        }

        // Top agent per returned city — rows arrive ordered by count DESC, so
        // the first row seen per city wins (name tiebreak for determinism).
        $topAgents = [];
        if ($cityIds !== []) {
            $agentRows = $this->scopedQuery()
                ->join('agents', function ($join) {
                    $join->on('agents.id', '=', 'listings.agent_id')
                        ->whereNull('agents.deleted_at');
                })
                ->whereIn(DB::raw($cityIdExpr), $cityIds)
                ->select(
                    DB::raw("{$cityIdExpr} as city_id"),
                    'listings.agent_id',
                    DB::raw("CONCAT_WS(' ', agents.first_name, agents.last_name) as full_name"),
                    DB::raw('COUNT(listings.id) as listing_count')
                )
                ->groupByRaw("{$cityIdExpr}, listings.agent_id, agents.first_name, agents.last_name")
                ->orderByDesc('listing_count')
                ->orderBy('full_name')
                ->get();
            foreach ($agentRows as $agentRow) {
                $cityId = (int) $agentRow->city_id;
                if (! isset($topAgents[$cityId])) {
                    $topAgents[$cityId] = [
                        'agent_id' => (int) $agentRow->agent_id,
                        'full_name' => (string) $agentRow->full_name,
                        'listing_count' => (int) $agentRow->listing_count,
                    ];
                }
            }
        }

        // Top team per returned city — same first-row-wins reduction. DISTINCT
        // listing count so multi-team agents never double count within a team.
        $topTeams = [];
        if ($cityIds !== []) {
            $teamRows = $this->scopedQuery()
                ->join('agents', function ($join) {
                    $join->on('agents.id', '=', 'listings.agent_id')
                        ->whereNull('agents.deleted_at');
                })
                ->join('team_agents as ta', function ($join) {
                    $join->on('ta.agent_id', '=', 'listings.agent_id')
                        ->where('ta.status', 'active');
                })
                ->join('teams', 'teams.id', '=', 'ta.team_id')
                ->whereIn(DB::raw($cityIdExpr), $cityIds)
                ->select(
                    DB::raw("{$cityIdExpr} as city_id"),
                    'teams.id as team_id',
                    'teams.name as team_name',
                    DB::raw('COUNT(DISTINCT listings.id) as listing_count')
                )
                ->groupByRaw("{$cityIdExpr}, teams.id, teams.name")
                ->orderByDesc('listing_count')
                ->orderBy('team_name')
                ->get();
            foreach ($teamRows as $teamRow) {
                $cityId = (int) $teamRow->city_id;
                if (! isset($topTeams[$cityId])) {
                    $topTeams[$cityId] = [
                        'team_id' => (int) $teamRow->team_id,
                        'team_name' => (string) $teamRow->team_name,
                        'listing_count' => (int) $teamRow->listing_count,
                    ];
                }
            }
        }

        $data = $rows->map(function ($row) use ($cityMeta, $topAgents, $topTeams) {
            $cityId = (int) $row->city_id;
            $meta = $cityMeta[$cityId] ?? null;

            return [
                'city_id' => $cityId,
                'city_name' => $meta !== null ? (string) $meta->city_name : '—',
                'province_name' => $meta !== null && $meta->province_name !== null
                    ? (string) $meta->province_name
                    : null,
                'agents_count' => (int) $row->agents_count,
                'listing_count' => (int) $row->listing_count,
                'verified_count' => (int) $row->verified_count,
                'top_agent' => $topAgents[$cityId] ?? null,
                'top_team' => $topTeams[$cityId] ?? null,
            ];
        })->all();

        // Re-apply the ranking now that canonical names are known (the SQL
        // tiebreak was city_id, before names were resolved). Order by the
        // active rank metric so the toggle reorders cities too.
        $rankKey = $this->qualifiedOnly ? 'verified_count' : 'listing_count';
        usort($data, fn ($a, $b) => [$b[$rankKey], $a['city_name']] <=> [$a[$rankKey], $b['city_name']]);

        return [$data, $totalGroups];
    }

    /**
     * Per-office-region ranking (agents.region, the OfficeRegionMap key a
     * Secretary is scoped to). Agents with no region fall into a synthetic
     * 'unassigned' bucket.
     *
     * @return array{0: array, 1: int}
     */
    private function byOfficeRegion(int $limit): array
    {
        $rows = $this->scopedQuery()
            ->join('agents', function ($join) {
                $join->on('agents.id', '=', 'listings.agent_id')
                    ->whereNull('agents.deleted_at');
            })
            ->select(
                DB::raw("COALESCE(agents.region, 'unassigned') as region"),
                DB::raw('COUNT(listings.id) as listing_count'),
                DB::raw('COUNT(DISTINCT listings.agent_id) as agents_count')
            )
            ->groupByRaw("COALESCE(agents.region, 'unassigned')")
            ->orderByDesc('listing_count')
            ->orderBy('region')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->scopedQuery()
            ->join('agents', function ($join) {
                $join->on('agents.id', '=', 'listings.agent_id')
                    ->whereNull('agents.deleted_at');
            })
            ->count(DB::raw("DISTINCT COALESCE(agents.region, 'unassigned')"));

        $data = $rows->map(fn ($row) => [
            'region' => (string) $row->region,
            // Official office label ('metro-manila' → 'Metro Manila');
            // label() falls back to ucfirst, which also covers 'unassigned'.
            'region_label' => OfficeRegionMap::label((string) $row->region),
            'agents_count' => (int) $row->agents_count,
            'listing_count' => (int) $row->listing_count,
        ])->all();

        return [$data, $totalGroups];
    }

    /**
     * Individual listings (newest first) — the agent drill-down: which
     * listings did this creator make, and where. City/province come off the
     * canonical location coalesce; the administrative region (RegionMap, e.g.
     * 'central-visayas') is derived from the province name. No ranking here —
     * rows are a creation log, so total_groups is simply the pre-limit count.
     *
     * @return array{0: array, 1: int}
     */
    private function byListing(int $limit): array
    {
        // Left joins (unlike ListingByTypeService's inner joins) — a listing
        // with a missing attribute row must still show in the creation log.
        $rows = $this->scopedQuery()
            ->leftJoin('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->leftJoin('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->select(
                'listings.id as listing_id',
                'listings.name',
                'listings.code',
                'listings.featured_photo',
                'listings.created_at',
                'listings.verification_status',
                'properties.ats_status',
                'property_types.name as type_name',
                'property_subtypes.name as subtype_name',
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name')
            )
            ->orderByDesc('listings.created_at')
            ->orderBy('listings.id')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->scopedQuery()->count(DB::raw('DISTINCT listings.id'));

        $data = $rows->map(function ($row) {
            $provinceName = $row->province_name !== null ? (string) $row->province_name : null;
            $regionKey = RegionMap::regionOf($provinceName);

            // featured_photo is a JSON array of URLs on the model ('array'
            // cast), but this is the raw query builder — decode and take the
            // first as the row's thumbnail.
            $photo = null;
            if ($row->featured_photo !== null) {
                $decoded = json_decode((string) $row->featured_photo, true);
                if (is_array($decoded) && ! empty($decoded[0]) && is_string($decoded[0])) {
                    $photo = $decoded[0];
                } elseif (is_string($decoded) && $decoded !== '') {
                    $photo = $decoded;
                }
            }

            return [
                'listing_id' => (int) $row->listing_id,
                'name' => (string) $row->name,
                'code' => $row->code !== null ? (string) $row->code : null,
                'photo' => $photo,
                'type' => $row->type_name !== null ? (string) $row->type_name : null,
                'subtype' => $row->subtype_name !== null ? (string) $row->subtype_name : null,
                'verification_status' => $row->verification_status !== null ? (string) $row->verification_status : null,
                'ats_status' => $row->ats_status !== null ? (string) $row->ats_status : null,
                'created_at' => (string) $row->created_at,
                'city_name' => $row->city_name !== null ? (string) $row->city_name : null,
                'province_name' => $provinceName,
                'region' => $regionKey,
                'region_label' => $regionKey !== null ? RegionMap::label($regionKey) : null,
            ];
        })->all();

        return [$data, $totalGroups];
    }

    /**
     * agents.avatar may hold a JSON-array string ("[\"https://…\"]") or a
     * JSON-encoded string ("\"https://…\"") — decode either to a bare URL
     * (same idiom as ListingByStatusService). Blank values become null so the
     * frontend falls back to its placeholder.
     */
    private function normalizeAvatar(?string $avatar): ?string
    {
        if ($avatar === null) {
            return null;
        }

        $trimmed = trim($avatar);
        if ($trimmed === '') {
            return null;
        }
        if ($trimmed[0] === '[' || $trimmed[0] === '"') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return ! empty($decoded[0]) ? (string) $decoded[0] : null;
            }
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }

            return null;
        }

        return $trimmed;
    }
}
