<?php

namespace App\Services\Inquiry;

use App\Support\IslandMap;
use Illuminate\Support\Facades\DB;

/**
 * Inquiry Analytics — hierarchical location drill-down. The level is inferred
 * from the scope set in configure():
 *   none            → island  (Luzon / Visayas / Mindanao)
 *   island=         → province
 *   province_id=    → city / municipality
 *   city_id=        → barangay
 * Every row carries inquiry_count, unique_clients, and a compact composition
 * (top category, top property type, price spread) so the table is informative
 * at every depth. Cross-filters (date / category / property type) apply at all
 * levels via the shared base query.
 */
class InquiryLocationService extends InquiryInsightsService
{
    public function locations(): array
    {
        $level = $this->resolveLevel();

        return $level === 'island'
            ? $this->islandLevel()
            : $this->entityLevel($level);
    }

    private function resolveLevel(): string
    {
        if ($this->cityId)     return 'barangay';
        if ($this->provinceId) return 'city';
        if ($this->island)     return 'province';

        return 'island';
    }

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

    private const COMPOSITION_SELECT = [
        'COUNT(*) as inquiry_count',
        'COUNT(DISTINCT chats.user_id) as unique_clients',
        "SUM(CASE WHEN categories.name = 'For Sale' THEN 1 ELSE 0 END) as for_sale",
        "SUM(CASE WHEN categories.name = 'For Rent' THEN 1 ELSE 0 END) as for_rent",
        "SUM(CASE WHEN categories.name = 'Foreclosure' THEN 1 ELSE 0 END) as foreclosure",
        'MIN(listings.price) as price_min',
        'MAX(listings.price) as price_max',
    ];

    /**
     * Top property type per group key. Returns [groupKey => ['name','count']].
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
            'inquiry_count' => (int) ($row->inquiry_count ?? 0),
            'unique_clients' => (int) ($row->unique_clients ?? 0),
        ];
    }

    // ── Island level ──────────────────────────────────────────────
    private function islandLevel(): array
    {
        $names = $this->provinceNames();
        $idList = fn (string $island) => (
            implode(',', array_map('intval', IslandMap::provinceIdsForIsland($names, $island))) ?: '0'
        );
        $p = $this->provinceIdExpr();
        $islandCase = "CASE
            WHEN {$p} IN ({$idList('luzon')}) THEN 'luzon'
            WHEN {$p} IN ({$idList('visayas')}) THEN 'visayas'
            WHEN {$p} IN ({$idList('mindanao')}) THEN 'mindanao'
            ELSE 'unclassified'
        END";

        $rows = $this->baseInquiryQuery()
            ->groupBy(DB::raw($islandCase))
            ->get(array_map(fn ($s) => DB::raw($s), array_merge(
                ["{$islandCase} as island_key"],
                self::COMPOSITION_SELECT
            )));

        $topTypes = $this->topTypesByGroup($islandCase);

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string) $r->island_key] = $r;
        }

        $data = [];
        $unclassifiedCount = 0;
        foreach (['luzon', 'visayas', 'mindanao'] as $key) {
            $r = $byKey[$key] ?? null;
            $data[] = [
                'level'          => 'island',
                'key'            => $key,
                'id'             => null,
                'name'           => IslandMap::label($key),
                'inquiry_count'  => $r ? (int) $r->inquiry_count : 0,
                'unique_clients' => $r ? (int) $r->unique_clients : 0,
                'composition'    => $this->composition($r, $topTypes[$key] ?? null),
            ];
        }
        if (isset($byKey['unclassified'])) {
            $r = $byKey['unclassified'];
            $unclassifiedCount = (int) $r->inquiry_count;
            if ($unclassifiedCount > 0) {
                $data[] = [
                    'level'          => 'island',
                    'key'            => 'unclassified',
                    'id'             => null,
                    'name'           => 'Unclassified',
                    'inquiry_count'  => $unclassifiedCount,
                    'unique_clients' => (int) $r->unique_clients,
                    'composition'    => $this->composition($r, $topTypes['unclassified'] ?? null),
                ];
            }
        }

        // Sort the three islands by count desc; keep unclassified last.
        usort($data, function ($a, $b) {
            if ($a['key'] === 'unclassified') return 1;
            if ($b['key'] === 'unclassified') return -1;
            return $b['inquiry_count'] <=> $a['inquiry_count'];
        });

        return [
            'data'   => $data,
            'totals' => $this->scopeTotals(),
            'meta'   => [
                'level'              => 'island',
                'parent'            => null,
                'unclassified_count' => $unclassifiedCount,
                'date_from'          => $this->dateFrom,
                'date_to'            => $this->dateTo,
                'category_id'        => $this->categoryId,
                'property_type'      => $this->propertyType,
            ],
        ];
    }

    // ── Province / City / Barangay level ──────────────────────────
    private function entityLevel(string $level): array
    {
        [$idExpr, $nameExpr] = match ($level) {
            'province' => [$this->provinceIdExpr(), $this->provinceNameExpr()],
            'city'     => [$this->cityIdExpr(), $this->cityNameExpr()],
            'barangay' => [$this->barangayIdExpr(), $this->barangayNameExpr()],
        };

        $rows = $this->baseInquiryQuery()
            ->groupBy(DB::raw($idExpr), DB::raw($nameExpr))
            ->get(array_map(fn ($s) => DB::raw($s), array_merge(
                ["{$idExpr} as gid", "{$nameExpr} as gname"],
                self::COMPOSITION_SELECT
            )));

        $topTypes = $this->topTypesByGroup($idExpr);

        $data = [];
        $unclassifiedCount = 0;
        foreach ($rows as $r) {
            $id = $r->gid !== null ? (int) $r->gid : null;
            $count = (int) $r->inquiry_count;
            $typeKey = $r->gid === null ? '__null__' : (string) $r->gid;

            if ($id === null) {
                $unclassifiedCount += $count;
                $data[] = [
                    'level'          => $level,
                    'id'             => null,
                    'name'           => 'Unclassified location',
                    'inquiry_count'  => $count,
                    'unique_clients' => (int) $r->unique_clients,
                    'composition'    => $this->composition($r, $topTypes[$typeKey] ?? null),
                ];
                continue;
            }

            $data[] = [
                'level'          => $level,
                'id'             => $id,
                'name'           => (string) $r->gname,
                'inquiry_count'  => $count,
                'unique_clients' => (int) $r->unique_clients,
                'composition'    => $this->composition($r, $topTypes[$typeKey] ?? null),
            ];
        }

        usort($data, fn ($a, $b) => $b['inquiry_count'] <=> $a['inquiry_count']);

        return [
            'data'   => $data,
            'totals' => $this->scopeTotals(),
            'meta'   => [
                'level'              => $level,
                'parent'            => [
                    'island'      => $this->island,
                    'province_id' => $this->provinceId,
                    'city_id'     => $this->cityId,
                ],
                'unclassified_count' => $unclassifiedCount,
                'date_from'          => $this->dateFrom,
                'date_to'            => $this->dateTo,
                'category_id'        => $this->categoryId,
                'property_type'      => $this->propertyType,
            ],
        ];
    }

    private function composition($row, ?array $topType): array
    {
        if (!$row) {
            return [
                'top_category' => ['key' => 'for_sale', 'count' => 0],
                'top_type'     => null,
                'price_min'    => null,
                'price_max'    => null,
            ];
        }

        return [
            'top_category' => $this->topCategory($row),
            'top_type'     => $topType,
            'price_min'    => $row->price_min !== null ? (float) $row->price_min : null,
            'price_max'    => $row->price_max !== null ? (float) $row->price_max : null,
        ];
    }
}
