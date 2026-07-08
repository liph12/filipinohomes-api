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
    public function topCreators(string $groupBy, ?string $dateStart = null, ?string $dateEnd = null, int $limit = 20, ?int $cityId = null, ?int $teamId = null, ?int $agentId = null): array
    {
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
        $totalListings = (int) $this->baseListingQuery()->count();

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
                'total_listings' => $totalListings,
                'total_groups' => $totalGroups,
            ],
        ];
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
        $rows = $this->baseListingQuery()
            ->join('agents', function ($join) {
                $join->on('agents.id', '=', 'listings.agent_id')
                    ->whereNull('agents.deleted_at');
            })
            ->select(
                'listings.agent_id',
                DB::raw("CONCAT_WS(' ', agents.first_name, agents.last_name) as full_name"),
                'agents.avatar',
                'agents.region',
                DB::raw('COUNT(listings.id) as listing_count')
            )
            ->groupBy('listings.agent_id', 'agents.first_name', 'agents.last_name', 'agents.avatar', 'agents.region')
            ->orderByDesc('listing_count')
            ->orderBy('full_name')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->baseListingQuery()
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
        $rows = $this->baseListingQuery()
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
                DB::raw('COUNT(DISTINCT listings.agent_id) as agents_count')
            )
            ->groupBy('teams.id', 'teams.name', 'teams.logo')
            ->orderByDesc('listing_count')
            ->orderBy('team_name')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->baseListingQuery()
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

        $rows = $this->baseListingQuery()
            ->whereNotNull(DB::raw($cityIdExpr))
            ->select(
                DB::raw("{$cityIdExpr} as city_id"),
                DB::raw('COUNT(listings.id) as listing_count'),
                DB::raw('COUNT(DISTINCT listings.agent_id) as agents_count')
            )
            ->groupByRaw($cityIdExpr)
            ->orderByDesc('listing_count')
            ->orderBy('city_id')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->baseListingQuery()
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
            $agentRows = $this->baseListingQuery()
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
            $teamRows = $this->baseListingQuery()
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
                'top_agent' => $topAgents[$cityId] ?? null,
                'top_team' => $topTeams[$cityId] ?? null,
            ];
        })->all();

        // Re-apply the count-desc / name-asc ordering now that canonical names
        // are known (the SQL tiebreak was city_id, before names were resolved).
        usort($data, fn ($a, $b) => [$b['listing_count'], $a['city_name']] <=> [$a['listing_count'], $b['city_name']]);

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
        $rows = $this->baseListingQuery()
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

        $totalGroups = (int) $this->baseListingQuery()
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
        $rows = $this->baseListingQuery()
            ->leftJoin('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->leftJoin('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->leftJoin('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->select(
                'listings.id as listing_id',
                'listings.name',
                'listings.code',
                'listings.featured_photo',
                'listings.created_at',
                'property_types.name as type_name',
                'property_subtypes.name as subtype_name',
                DB::raw('COALESCE(project_cities.name, property_cities.name) as city_name'),
                DB::raw('COALESCE(project_provinces.name, property_provinces.name) as province_name')
            )
            ->orderByDesc('listings.created_at')
            ->orderBy('listings.id')
            ->limit($limit)
            ->get();

        $totalGroups = (int) $this->baseListingQuery()->count(DB::raw('DISTINCT listings.id'));

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
