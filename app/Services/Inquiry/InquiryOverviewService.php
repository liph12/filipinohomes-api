<?php

namespace App\Services\Inquiry;

use Illuminate\Support\Facades\DB;

/**
 * Inquiry Analytics — overview breakdowns for the current filter scope:
 * totals, category (sale/rent/foreclosure), property type, property subtype,
 * and category-aware price buckets.
 */
class InquiryOverviewService extends InquiryInsightsService
{
    public function overview(): array
    {
        // Totals — one scan.
        $totalsRow = $this->baseInquiryQuery()
            ->selectRaw('COUNT(*) as total_inquiries, COUNT(DISTINCT chats.user_id) as unique_clients')
            ->first();

        // Category (sale/rent/foreclosure) — conditional sums, one row.
        $catRow = $this->baseInquiryQuery()
            ->selectRaw("
                SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale,
                SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent,
                SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure
            ")
            ->first();

        // Conversion — how many of the INQUIRED listings have since transacted
        // vs are still active. Counts DISTINCT listings by their CURRENT
        // property status (a listing-level proxy; we can't attribute a sale to a
        // specific inquiry). "active" = inquired but not yet sold/rented/leased.
        $convRow = $this->baseInquiryQuery()
            ->selectRaw("
                COUNT(DISTINCT listings.id) as total_listings,
                COUNT(DISTINCT CASE WHEN properties.status = 'sold'   THEN listings.id END) as sold,
                COUNT(DISTINCT CASE WHEN properties.status = 'rented' THEN listings.id END) as rented,
                COUNT(DISTINCT CASE WHEN properties.status = 'leased' THEN listings.id END) as leased,
                COUNT(DISTINCT CASE WHEN properties.status = 'active' THEN listings.id END) as active
            ")
            ->first();

        // Property type.
        $byType = $this->baseInquiryQuery()
            ->groupBy('property_types.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                DB::raw('property_types.name as name'),
                DB::raw('COUNT(*) as count'),
            ])
            ->map(fn ($r) => ['name' => (string) $r->name, 'count' => (int) $r->count])
            ->all();

        // Property subtype — top 12 + "Other".
        $subtypeRows = $this->baseInquiryQuery()
            ->groupBy('property_subtypes.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                DB::raw('property_subtypes.name as name'),
                DB::raw('COUNT(*) as count'),
            ]);
        $bySubtype = [];
        $otherCount = 0;
        foreach ($subtypeRows as $i => $r) {
            if ($i < 12) {
                $bySubtype[] = ['name' => (string) $r->name, 'count' => (int) $r->count];
            } else {
                $otherCount += (int) $r->count;
            }
        }
        if ($otherCount > 0) {
            $bySubtype[] = ['name' => 'Other', 'count' => $otherCount];
        }

        // Price buckets — grouped by the category-aware CASE, split sale/rent.
        $bucketRows = $this->baseInquiryQuery()
            ->groupBy(DB::raw($this->priceBucketExpr()))
            ->get([
                DB::raw($this->priceBucketExpr() . ' as bucket'),
                DB::raw('COUNT(*) as count'),
            ]);
        $bucketCounts = [];
        foreach ($bucketRows as $r) {
            $bucketCounts[(string) $r->bucket] = (int) $r->count;
        }

        $labels = self::priceBucketLabels();
        $saleBuckets = array_map(fn ($b) => [
            'key'   => $b['key'],
            'label' => $b['label'],
            'count' => $bucketCounts[$b['key']] ?? 0,
        ], $labels['sale']);
        $rentBuckets = array_map(fn ($b) => [
            'key'   => $b['key'],
            'label' => $b['label'],
            'count' => $bucketCounts[$b['key']] ?? 0,
        ], $labels['rent']);

        return [
            'totals' => [
                'total_inquiries' => (int) ($totalsRow->total_inquiries ?? 0),
                'unique_clients'  => (int) ($totalsRow->unique_clients ?? 0),
            ],
            'breakdowns' => [
                'by_category' => [
                    'for_sale'    => (int) ($catRow->for_sale ?? 0),
                    'for_rent'    => (int) ($catRow->for_rent ?? 0),
                    'foreclosure' => (int) ($catRow->foreclosure ?? 0),
                ],
                'by_type'    => $byType,
                'by_subtype' => $bySubtype,
                'price_buckets' => [
                    'sale_buckets' => $saleBuckets,
                    'rent_buckets' => $rentBuckets,
                ],
                'conversion' => [
                    'total_listings' => (int) ($convRow->total_listings ?? 0),
                    'sold'           => (int) ($convRow->sold ?? 0),
                    'rented'         => (int) ($convRow->rented ?? 0),
                    'leased'         => (int) ($convRow->leased ?? 0),
                    'active'         => (int) ($convRow->active ?? 0),
                ],
            ],
            'meta' => [
                'date_from'     => $this->dateFrom,
                'date_to'       => $this->dateTo,
                'category_id'   => $this->categoryId,
                'property_type' => $this->propertyType,
            ],
        ];
    }
}
