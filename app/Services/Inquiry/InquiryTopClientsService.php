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
    public function clients(int $page = 1, int $perPage = 25, string $sortBy = 'count', string $sortDir = 'desc'): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $totalClients = (int) $this->baseInquiryQuery()->distinct()->count('chats.user_id');
        $totalInquiries = (int) $this->baseInquiryQuery()->count();

        $orderCol = $sortBy === 'name' ? 'name' : 'inquiry_count';
        $dir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $query = $this->baseInquiryQuery()
            ->groupBy('chats.user_id', 'inq.name', 'inq.birthdate', 'inq.gender', 'inq.avatar', 'inq.mobile_no', 'inq.email')
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
                'inquiry_count' => (int) $r->inquiry_count,
                'inquired'      => [
                    'top_categories' => $topCategories[$uid] ?? [],
                    'top_types'      => $topTypes[$uid] ?? [],
                    'top_locations'  => $topLocations[$uid] ?? [],
                ],
            ];
        })->all();

        return [
            'data'   => $data,
            'totals' => [
                'total_clients'            => $totalClients,
                'total_inquiries_in_scope' => $totalInquiries,
            ],
            'meta' => [
                'page'          => $page,
                'per_page'      => $perPage,
                'last_page'     => max(1, (int) ceil($totalClients / $perPage)),
                'sort_by'       => $sortBy,
                'sort_dir'      => $dir,
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
