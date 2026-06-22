<?php

namespace App\Services\Inquiry;

use Illuminate\Support\Facades\DB;

/**
 * Inquiry Analytics — the actual listings behind an inquiry count (drill from a
 * location row, a client row, or a map cluster), and the individual inquiries
 * on a single listing. Both reuse baseInquiryQuery() so they honor the exact
 * same date / category / property-type / location / client scope as the rest of
 * the dashboard.
 */
class InquiryListingsService extends InquiryInsightsService
{
    /** One row per inquired listing within the current scope. */
    public function listings(int $page = 1, int $perPage = 25, string $sortBy = 'inquiries', string $sortDir = 'desc'): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        // Distinct inquired listings + total inquiries in scope.
        $totalListings = (int) $this->baseInquiryQuery()->distinct()->count('listings.id');
        $totalInquiries = (int) $this->baseInquiryQuery()->count();

        $orderCol = match ($sortBy) {
            'price'   => 'price',
            'newest'  => 'latest_inquiry_at',
            default   => 'inquiry_count',
        };
        $dir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $rows = $this->baseInquiryQuery()
            ->leftJoin('agents', 'agents.id', '=', 'listings.agent_id')
            ->groupBy(
                DB::raw('listings.id'),
                DB::raw('listings.name'),
                DB::raw('listings.slug'),
                DB::raw('listings.code'),
                DB::raw('listings.price'),
                DB::raw('listings.featured_photo'),
                DB::raw('properties.status'),
                DB::raw('categories.name'),
                DB::raw('property_types.name'),
                DB::raw('property_subtypes.name'),
                DB::raw($this->cityNameExpr()),
                DB::raw($this->provinceNameExpr()),
                DB::raw($this->barangayNameExpr()),
                DB::raw('agents.first_name'),
                DB::raw('agents.last_name')
            )
            ->orderBy($orderCol, $dir)
            ->orderBy('listings.id', 'asc') // stable tiebreaker for pagination
            ->forPage($page, $perPage)
            ->get([
                DB::raw('listings.id as id'),
                DB::raw('listings.name as name'),
                DB::raw('listings.slug as slug'),
                DB::raw('listings.code as code'),
                DB::raw('listings.price as price'),
                DB::raw('listings.featured_photo as featured_photo'),
                DB::raw('properties.status as status'),
                DB::raw('categories.name as category'),
                DB::raw('property_types.name as property_type'),
                DB::raw('property_subtypes.name as subtype'),
                DB::raw($this->cityNameExpr() . ' as city_name'),
                DB::raw($this->provinceNameExpr() . ' as province_name'),
                DB::raw($this->barangayNameExpr() . ' as barangay_name'),
                DB::raw("TRIM(CONCAT(COALESCE(agents.first_name,''),' ',COALESCE(agents.last_name,''))) as agent_name"),
                DB::raw('COUNT(*) as inquiry_count'),
                DB::raw('COUNT(DISTINCT chats.user_id) as unique_clients'),
                DB::raw('MAX(chats.created_at) as latest_inquiry_at'),
            ]);

        $data = $rows->map(function ($r) {
            $loc = array_filter([$r->barangay_name, $r->city_name, $r->province_name]);

            return [
                'id'                => (int) $r->id,
                'name'              => (string) ($r->name ?? 'Untitled listing'),
                'slug'              => $r->slug,
                'code'              => $r->code,
                'price'             => $r->price !== null ? (float) $r->price : null,
                'photo'             => $this->firstPhoto($r->featured_photo),
                'status'            => $r->status,
                'category'          => $r->category,
                'property_type'     => $r->property_type,
                'subtype'           => $r->subtype,
                'location'          => implode(', ', $loc),
                'agent_name'        => $r->agent_name ?: null,
                'inquiry_count'     => (int) $r->inquiry_count,
                'unique_clients'    => (int) $r->unique_clients,
                'latest_inquiry_at' => $r->latest_inquiry_at,
            ];
        })->all();

        return [
            'data'   => $data,
            'totals' => [
                'total_listings'           => $totalListings,
                'total_inquiries_in_scope' => $totalInquiries,
            ],
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($totalListings / $perPage)),
                'sort_by'   => $sortBy,
                'sort_dir'  => $dir,
            ],
        ];
    }

    /** Individual inquiries on a single listing (client + date), within scope. */
    public function listingInquiries(int $listingId, int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);

        $base = fn () => $this->baseInquiryQuery()->where('listings.id', $listingId);

        $total = (int) $base()->count();

        $rows = $base()
            ->orderByDesc('chats.created_at')
            ->forPage($page, $perPage)
            ->get([
                DB::raw('chats.id as chat_id'),
                DB::raw('inq.id as client_id'),
                DB::raw('inq.name as client_name'),
                DB::raw('inq.birthdate as birthdate'),
                DB::raw('inq.gender as gender'),
                DB::raw('chats.created_at as inquired_at'),
            ]);

        // Listing header (name + slug) for the drawer's level-2 title.
        $listing = DB::table('listings')->where('id', $listingId)->first(['name', 'slug']);

        $data = $rows->map(fn ($r) => [
            'chat_id'     => (int) $r->chat_id,
            'client_id'   => (int) $r->client_id,
            'client_name' => (string) ($r->client_name ?? 'Unknown'),
            'birthdate'   => $r->birthdate ? substr((string) $r->birthdate, 0, 10) : null,
            'gender'      => $r->gender ?: null,
            'inquired_at' => $r->inquired_at,
        ])->all();

        return [
            'data'    => $data,
            'listing' => [
                'id'   => $listingId,
                'name' => $listing->name ?? 'Listing',
                'slug' => $listing->slug ?? null,
            ],
            'totals' => ['total_inquiries' => $total],
            'meta'   => [
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Master table: one row per inquiry (chat) in scope — the flat "see it all"
     * view. Sortable by date / price / client / listing; searchable by client
     * or listing name; paginated.
     */
    public function inquiries(int $page = 1, int $perPage = 25, string $sortBy = 'date', string $sortDir = 'desc', ?string $search = null): array
    {
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);
        $dir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $base = function () use ($search) {
            $q = $this->baseInquiryQuery();
            if ($search !== null && trim($search) !== '') {
                $term = '%' . trim($search) . '%';
                $q->where(function ($w) use ($term) {
                    $w->where('inq.name', 'like', $term)
                      ->orWhere('listings.name', 'like', $term);
                });
            }
            return $q;
        };

        $orderCol = match ($sortBy) {
            'price'   => 'listings.price',
            'client'  => 'inq.name',
            'listing' => 'listings.name',
            default   => 'chats.created_at',
        };

        $total = (int) $base()->count();

        $rows = $base()
            ->orderBy($orderCol, $dir)
            ->orderBy('chats.id', 'desc')
            ->forPage($page, $perPage)
            ->get([
                DB::raw('chats.id as chat_id'),
                DB::raw('chats.created_at as inquired_at'),
                DB::raw('inq.name as client_name'),
                DB::raw('listings.id as listing_id'),
                DB::raw('listings.name as listing_name'),
                DB::raw('listings.slug as listing_slug'),
                DB::raw('listings.price as price'),
                DB::raw('properties.status as status'),
                DB::raw('categories.name as category'),
                DB::raw('property_types.name as property_type'),
                DB::raw($this->cityNameExpr() . ' as city_name'),
                DB::raw($this->provinceNameExpr() . ' as province_name'),
            ]);

        $data = $rows->map(function ($r) {
            $loc = array_filter([$r->city_name, $r->province_name]);

            return [
                'chat_id'       => (int) $r->chat_id,
                'inquired_at'   => $r->inquired_at,
                'client_name'   => (string) ($r->client_name ?? 'Unknown'),
                'listing_id'    => (int) $r->listing_id,
                'listing_name'  => (string) ($r->listing_name ?? 'Untitled listing'),
                'listing_slug'  => $r->listing_slug,
                'price'         => $r->price !== null ? (float) $r->price : null,
                'status'        => $r->status,
                'category'      => $r->category,
                'property_type' => $r->property_type,
                'location'      => implode(', ', $loc),
            ];
        })->all();

        return [
            'data'   => $data,
            'totals' => ['total_inquiries' => $total],
            'meta'   => [
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'sort_by'   => $sortBy,
                'sort_dir'  => $dir,
            ],
        ];
    }

    /** First photo URL from the featured_photo JSON column. */
    private function firstPhoto($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (is_array($decoded)) {
            $first = $decoded[0] ?? null;
            if (is_string($first)) {
                return $first;
            }
            if (is_array($first)) {
                return $first['url'] ?? $first['path'] ?? null;
            }
        }
        if (is_string($decoded)) {
            return $decoded;
        }

        return null;
    }
}
