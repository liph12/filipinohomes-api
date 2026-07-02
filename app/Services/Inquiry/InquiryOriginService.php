<?php

namespace App\Services\Inquiry;

use Illuminate\Support\Facades\DB;

/**
 * Inquiry Analytics — "where the inquiry came from" drill-down. Groups the live
 * inquiries (chats type='listing') by the INQUIRING CLIENT's origin (from the
 * user_info geo joined in the shared base), independent of the listing's PH
 * location. Level is inferred from configure():
 *   none            → country
 *   origin_country= → region  (user_info.state)
 *   origin_region=  → city
 *
 * Blank / 'Unknown' origins fold into a single "Unknown" bucket (kept last,
 * unranked) — mirroring how InquiryLocationService discloses unclassified
 * listings. Each row carries inquiry_count, unique_clients, and the same
 * composition shape (category split, top type, price spread) as the location
 * tab, so the two drilldowns render with the same columns. The composition
 * helpers are intentionally kept local here so the production location service
 * stays untouched.
 */
class InquiryOriginService extends InquiryInsightsService
{
    public function origins(): array
    {
        $level = $this->resolveLevel();
        $expr  = match ($level) {
            'country' => $this->originCountryExpr(),
            'region'  => $this->originRegionExpr(),
            'city'    => $this->originCityExpr(),
        };

        $rows = $this->baseInquiryQuery()
            ->groupBy(DB::raw($expr))
            ->get(array_map(fn ($s) => DB::raw($s), array_merge(
                ["{$expr} as gname"],
                self::COMPOSITION_SELECT
            )));

        $topTypes = $this->topTypesByGroup($expr);

        $data    = [];
        $unknown = null;
        foreach ($rows as $r) {
            $name    = $r->gname !== null ? (string) $r->gname : null;
            $count   = (int) $r->inquiry_count;
            $typeKey = $r->gname === null ? '__null__' : (string) $r->gname;
            $comp    = $this->composition($r, $topTypes[$typeKey] ?? null);

            if ($name === null) {
                // Unknown-origin bucket: disclosed but never ranked (it has no
                // drill target and would otherwise outrank real countries).
                $unknown = [
                    'level'          => $level,
                    'key'            => 'unknown',
                    'name'           => 'Unknown',
                    'country'        => null,
                    'inquiry_count'  => $count,
                    'unique_clients' => (int) $r->unique_clients,
                    'composition'    => $comp,
                ];
                continue;
            }

            $data[] = [
                'level'          => $level,
                // At country level `name` IS the ISO2 code (frontend maps it to a
                // full name); at region/city it's the plain region / city string.
                'key'            => $name,
                'name'           => $name,
                'country'        => $level === 'country' ? $name : $this->originCountry,
                'inquiry_count'  => $count,
                'unique_clients' => (int) $r->unique_clients,
                'composition'    => $comp,
            ];
        }

        usort($data, fn ($a, $b) => $b['inquiry_count'] <=> $a['inquiry_count']);
        if ($unknown && $unknown['inquiry_count'] > 0) {
            $data[] = $unknown;
        }

        return [
            'data'   => $data,
            'totals' => $this->scopeTotals(),
            'meta'   => [
                'level'  => $level,
                'parent' => [
                    'origin_country' => $this->originCountry,
                    'origin_region'  => $this->originRegion,
                ],
                'date_from' => $this->dateFrom,
                'date_to'   => $this->dateTo,
            ],
        ];
    }

    private function resolveLevel(): string
    {
        if ($this->originRegion)  return 'city';
        if ($this->originCountry) return 'region';

        return 'country';
    }

    // ── Composition helpers (local copies; keep the location service untouched) ──
    private const COMPOSITION_SELECT = [
        'COUNT(*) as inquiry_count',
        'COUNT(DISTINCT chats.user_id) as unique_clients',
        "SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale",
        "SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent",
        "SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure",
        'MIN(listings.price) as price_min',
        'MAX(listings.price) as price_max',
    ];

    /** argmax of the three category sums → ['key','count']. */
    private function topCategory($row): array
    {
        $cats = [
            'for_sale'    => (int) ($row->for_sale ?? 0),
            'for_rent'    => (int) ($row->for_rent ?? 0),
            'foreclosure' => (int) ($row->foreclosure ?? 0),
        ];
        arsort($cats);
        $key = array_key_first($cats);

        return ['key' => $key, 'count' => $cats[$key]];
    }

    /**
     * Top property type per group key → [groupKey => ['name','count']].
     * $groupExpr is the same expression used to group the main query.
     */
    private function topTypesByGroup(string $groupExpr): array
    {
        $rows = $this->baseInquiryQuery()
            ->groupBy(DB::raw($groupExpr), DB::raw('property_types.name'))
            ->get([
                DB::raw($groupExpr . ' as gkey'),
                DB::raw('property_types.name as type_name'),
                DB::raw('COUNT(*) as c'),
            ]);

        $best = [];
        foreach ($rows as $r) {
            $k = $r->gkey === null ? '__null__' : (string) $r->gkey;
            $c = (int) $r->c;
            if (!isset($best[$k]) || $c > $best[$k]['count']) {
                $best[$k] = ['name' => (string) $r->type_name, 'count' => $c];
            }
        }

        return $best;
    }

    private function scopeTotals(): array
    {
        $row = $this->baseInquiryQuery()
            ->selectRaw('COUNT(*) as inquiry_count, COUNT(DISTINCT chats.user_id) as unique_clients')
            ->first();

        return [
            'inquiry_count'  => (int) ($row->inquiry_count ?? 0),
            'unique_clients' => (int) ($row->unique_clients ?? 0),
        ];
    }

    private function composition($row, ?array $topType): array
    {
        if (!$row) {
            return [
                'top_category' => ['key' => 'for_sale', 'count' => 0],
                'by_category'  => ['for_sale' => 0, 'for_rent' => 0, 'foreclosure' => 0],
                'top_type'     => null,
                'price_min'    => null,
                'price_max'    => null,
            ];
        }

        return [
            'top_category' => $this->topCategory($row),
            'by_category'  => [
                'for_sale'    => (int) ($row->for_sale ?? 0),
                'for_rent'    => (int) ($row->for_rent ?? 0),
                'foreclosure' => (int) ($row->foreclosure ?? 0),
            ],
            'top_type'  => $topType,
            'price_min' => $row->price_min !== null ? (float) $row->price_min : null,
            'price_max' => $row->price_max !== null ? (float) $row->price_max : null,
        ];
    }
}
