<?php

namespace App\Services\Inquiry;

use Illuminate\Support\Facades\DB;

/**
 * Inquiry Analytics — full, sortable, paginated list of inquiring clients for
 * the current filter scope (not just a "top N"). Each client: identity (+
 * birthdate/gender so the frontend can show age), inquiry count, and "what
 * they inquired" (top categories / types / locations).
 */
class InquiryTopClientsService extends InquiryInsightsService
{
    public function clients(int $page = 1, int $perPage = 25, string $sortBy = 'count', string $sortDir = 'desc', ?string $clientCountry = null): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        // Dropdown options: every country that has at least one inquiring
        // client in the current scope, with its client count — computed BEFORE
        // the country filter so the list stays complete while one is selected.
        // Filters/aggregates on user_info.country (the value the "From" column
        // shows), not the chats.origin_* stamp.
        $countries = $this->baseInquiryQuery()
            ->whereRaw("NULLIF(NULLIF(user_info.country, ''), 'Unknown') IS NOT NULL")
            ->groupBy('user_info.country')
            ->orderByDesc(DB::raw('COUNT(DISTINCT chats.user_id)'))
            ->get([
                DB::raw('user_info.country as code'),
                DB::raw('COUNT(DISTINCT chats.user_id) as clients'),
            ])
            ->map(fn ($r) => ['code' => (string) $r->code, 'clients' => (int) $r->clients])
            ->all();

        $scoped = fn () => $clientCountry
            ? $this->baseInquiryQuery()->where('user_info.country', $clientCountry)
            : $this->baseInquiryQuery();

        $totalClients = (int) $scoped()->distinct()->count('chats.user_id');
        $totalInquiries = (int) $scoped()->count();

        $orderCol = $sortBy === 'name' ? 'name' : 'inquiry_count';
        $dir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $query = $scoped()
            // user_info is 1:1 per client (joined in the base), so its geo
            // columns are safe GROUP BY members — they feed the "From" column.
            ->groupBy('chats.user_id', 'inq.name', 'inq.birthdate', 'inq.gender', 'inq.avatar', 'inq.mobile_no', 'inq.email', 'user_info.country', 'user_info.state', 'user_info.city')
            ->orderBy($orderCol, $dir);
        // Stable tiebreaker so pagination is deterministic.
        if ($orderCol !== 'inquiry_count') {
            $query->orderByDesc(DB::raw('COUNT(*)'));
        }
        $query->orderBy('chats.user_id', 'asc');

        $rows = $query
            ->forPage($page, $perPage)
            ->get([
                DB::raw('chats.user_id as user_id'),
                DB::raw('inq.name as name'),
                DB::raw('inq.avatar as avatar'),
                DB::raw('inq.mobile_no as mobile_no'),
                DB::raw('inq.email as email'),
                DB::raw('inq.birthdate as birthdate'),
                DB::raw('inq.gender as gender'),
                // Client origin ("From" column) — their user_info geo, captured
                // at login. Blank/'Unknown' folds to null for the frontend.
                DB::raw("NULLIF(NULLIF(user_info.country, ''), 'Unknown') as origin_country"),
                DB::raw("NULLIF(NULLIF(user_info.state, ''), 'Unknown') as origin_region"),
                DB::raw("NULLIF(NULLIF(user_info.city, ''), 'Unknown') as origin_city"),
                DB::raw('COUNT(*) as inquiry_count'),
            ]);

        $ids = $rows->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        $topCategories = $this->enrich($ids, 'categories.name');
        $topTypes      = $this->enrich($ids, 'property_types.name');
        $topLocations  = $this->enrich($ids, $this->cityNameExpr());

        $offset = ($page - 1) * $perPage;
        $data = $rows->values()->map(function ($r, $i) use ($topCategories, $topTypes, $topLocations, $offset) {
            $uid = (int) $r->user_id;

            return [
                'rank'          => $offset + $i + 1,
                'user_id'       => $uid,
                'name'          => (string) ($r->name ?? 'Unknown'),
                // Avatar returned raw (full URL or storage path / JSON array) —
                // the frontend resolves it the same way as everywhere else.
                'photo'         => $r->avatar ?: null,
                'phone'         => $r->mobile_no ?: null,
                'email'         => $r->email ?: null,
                'birthdate'     => $r->birthdate ? substr((string) $r->birthdate, 0, 10) : null,
                'gender'        => $r->gender ?: null,
                // "From" — where the client is located (login-captured geo).
                'origin'        => [
                    'country' => $r->origin_country ?: null, // ISO2
                    'region'  => $r->origin_region ?: null,
                    'city'    => $r->origin_city ?: null,
                ],
                'inquiry_count' => (int) $r->inquiry_count,
                'inquired'      => [
                    'top_categories' => $topCategories[$uid] ?? [],
                    'top_types'      => $topTypes[$uid] ?? [],
                    'top_locations'  => $topLocations[$uid] ?? [],
                ],
            ];
        })->all();

        return [
            'data'      => $data,
            // Country dropdown options (ISO2 + client count), full scope.
            'countries' => $countries,
            'totals' => [
                'total_clients'            => $totalClients,
                'total_inquiries_in_scope' => $totalInquiries,
            ],
            'meta' => [
                'page'           => $page,
                'per_page'       => $perPage,
                'last_page'      => max(1, (int) ceil($totalClients / $perPage)),
                'sort_by'        => $sortBy,
                'sort_dir'       => $dir,
                'client_country' => $clientCountry,
                'date_from'     => $this->dateFrom,
                'date_to'       => $this->dateTo,
                'category_id'   => $this->categoryId,
                'property_type' => $this->propertyType,
            ],
        ];
    }

    /**
     * For the given client ids, return [user_id => [['name','count'], ...top 2]]
     * grouped by the given label expression (column name or raw SQL).
     */
    private function enrich(array $ids, string $labelExpr): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->baseInquiryQuery()
            ->whereIn('chats.user_id', $ids)
            ->groupBy('chats.user_id', DB::raw($labelExpr))
            ->get([
                DB::raw('chats.user_id as user_id'),
                DB::raw($labelExpr . ' as label'),
                DB::raw('COUNT(*) as c'),
            ]);

        $byUser = [];
        foreach ($rows as $r) {
            if ($r->label === null || $r->label === '') {
                continue;
            }
            $byUser[(int) $r->user_id][] = ['name' => (string) $r->label, 'count' => (int) $r->c];
        }

        foreach ($byUser as &$list) {
            usort($list, fn ($a, $b) => $b['count'] <=> $a['count']);
            $list = array_slice($list, 0, 2);
        }
        unset($list);

        return $byUser;
    }
}
