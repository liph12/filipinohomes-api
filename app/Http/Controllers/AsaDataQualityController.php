<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Listings ASA can't use" — the admin read over public listings whose data keeps them out of
 * ASA-GPT's answers: a placeholder price (₱990 for a house), no size (so no ₱/sqm, no valuation
 * comparables), no bedroom count (no unit-size tier), no map pin (no "near IT Park", no distance),
 * or no city at all (in no price guide, no area snapshot). Grouped by reason, by agent and by type,
 * with the listings themselves, so each agent can be sent their own list.
 */
class AsaDataQualityController extends Controller
{
    private const RENT_FLOOR = 1000;

    private const SALE_FLOOR = 100000;

    private const MIN_SQM = 10;

    public const REASONS = [
        'placeholder_price' => 'Placeholder price',
        'no_size' => 'No floor or lot area',
        'no_bedrooms' => 'No bedroom count',
        'no_pin' => 'No map pin',
        'no_area' => 'No city or barangay',
    ];

    public function index(Request $request): JsonResponse
    {
        $reason = (string) $request->input('reason', '');
        $agent = trim((string) $request->input('agent', ''));
        $q = trim((string) $request->input('q', ''));
        $perPage = max(10, min(200, (int) $request->input('per_page', 50)));
        $page = max(1, (int) $request->input('page', 1));
        if ($reason !== '' && ! isset(self::REASONS[$reason])) {
            $reason = '';
        }

        $summary = Cache::remember('asa:data-quality:summary:v1', 600, fn () => $this->summary());

        $list = $this->base()->whereRaw(self::anyReasonSql());
        if ($reason !== '') {
            $list->whereRaw(self::reasonSql()[$reason]);
        }
        if ($agent !== '') {
            if (ctype_digit($agent)) {
                $list->where('agents.id', (int) $agent);
            } else {
                $list->where(function ($w) use ($agent) {
                    foreach (preg_split('/\s+/', $agent) as $word) {
                        $w->where(fn ($s) => $s->where('agents.first_name', 'like', "%{$word}%")->orWhere('agents.last_name', 'like', "%{$word}%")->orWhere('users.email', 'like', "%{$word}%"));
                    }
                });
            }
        }
        if ($q !== '') {
            $list->where('listings.name', 'like', "%{$q}%");
        }
        $total = (int) (clone $list)->count();
        $rows = $list->select(
            'listings.id', 'listings.name', 'listings.slug', 'listings.price', 'listings.created_at',
            'categories.name as category', 'property_types.name as type', 'property_subtypes.name as subtype',
            'properties.address', 'property_attributes.floor_area', 'property_attributes.lot_area', 'property_attributes.bedroom_count',
            'agents.id as agent_id', 'agents.first_name', 'agents.last_name', 'users.email as agent_email',
        )
            ->selectRaw(self::flagsSelectSql())
            ->orderByDesc('listings.created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'slug' => (string) $r->slug,
                'price' => $r->price === null ? null : (float) $r->price,
                'category' => (string) $r->category,
                'type' => (string) $r->type,
                'subtype' => (string) $r->subtype,
                'address' => (string) ($r->address ?? ''),
                'floor_area' => $r->floor_area === null ? null : (float) $r->floor_area,
                'lot_area' => $r->lot_area === null ? null : (float) $r->lot_area,
                'bedrooms' => $r->bedroom_count === null ? null : (int) $r->bedroom_count,
                'agent' => ['id' => (int) $r->agent_id, 'name' => trim("{$r->first_name} {$r->last_name}"), 'email' => $r->agent_email],
                'reasons' => array_values(array_filter(array_keys(self::REASONS), fn ($k) => (int) $r->{"f_{$k}"} === 1)),
                'created_at' => (string) $r->created_at,
            ])
            ->all();

        return response()->json($summary + [
            'reasons' => self::REASONS,
            'listings' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'rows' => $rows],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $public = (int) $this->base()->count();
        $affected = (int) $this->base()->whereRaw(self::anyReasonSql())->count();

        $byReason = [];
        $sums = $this->base()->selectRaw(implode(', ', array_map(fn ($k) => "SUM(CASE WHEN ".self::reasonSql()[$k]." THEN 1 ELSE 0 END) as {$k}", array_keys(self::REASONS))))->first();
        foreach (array_keys(self::REASONS) as $k) {
            $byReason[$k] = (int) ($sums->{$k} ?? 0);
        }

        $byAgent = $this->base()
            ->select('agents.id', 'agents.first_name', 'agents.last_name', 'users.email')
            ->selectRaw('COUNT(*) as listings')
            ->selectRaw('SUM(CASE WHEN '.self::anyReasonSql().' THEN 1 ELSE 0 END) as affected')
            ->selectRaw(implode(', ', array_map(fn ($k) => "SUM(CASE WHEN ".self::reasonSql()[$k]." THEN 1 ELSE 0 END) as {$k}", array_keys(self::REASONS))))
            ->groupBy('agents.id', 'agents.first_name', 'agents.last_name', 'users.email')
            ->having('affected', '>', 0)
            ->orderByDesc('affected')
            ->limit(40)
            ->get()
            ->map(fn ($r) => [
                'agent_id' => (int) $r->id,
                'name' => trim("{$r->first_name} {$r->last_name}"),
                'email' => $r->email,
                'listings' => (int) $r->listings,
                'affected' => (int) $r->affected,
                'reasons' => array_combine(array_keys(self::REASONS), array_map(fn ($k) => (int) $r->{$k}, array_keys(self::REASONS))),
            ])->all();

        $byType = $this->base()
            ->select('property_types.name as type')
            ->selectRaw('COUNT(*) as listings')
            ->selectRaw('SUM(CASE WHEN '.self::anyReasonSql().' THEN 1 ELSE 0 END) as affected')
            ->groupBy('property_types.name')
            ->orderByDesc('affected')
            ->get()
            ->map(fn ($r) => ['type' => (string) $r->type, 'listings' => (int) $r->listings, 'affected' => (int) $r->affected])->all();

        return [
            'computed_at' => now()->toIso8601String(),
            'totals' => ['public_listings' => $public, 'affected' => $affected, 'by_reason' => $byReason],
            'by_agent' => $byAgent,
            'by_type' => $byType,
        ];
    }

    /** Live public listings with every table a reason needs; the city is LEFT-joined so its absence shows. */
    private function base(): Builder
    {
        return DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('agents', 'agents.id', '=', 'listings.agent_id')
            ->leftJoin('users', 'users.id', '=', 'agents.user_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->leftJoin('barangays as addr_b', 'addr_b.id', '=', 'properties.address_id')
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->whereNull('property_attributes.deleted_at')
            ->whereNull('agents.deleted_at')
            ->where('agents.status', 'active')
            ->where('listings.visibility', 'public')
            ->where(function ($w) {
                $w->whereNull('listings.verification_status')->orWhere('listings.verification_status', '!=', 'flagged');
            });
    }

    /**
     * Each reason as a SQL predicate over the joined tables.
     *
     * @return array<string, string>
     */
    private static function reasonSql(): array
    {
        $rent = self::RENT_FLOOR;
        $sale = self::SALE_FLOOR;
        $sqm = self::MIN_SQM;

        return [
            'placeholder_price' => "(listings.price IS NULL OR (categories.name = 'For Rent' AND listings.price < {$rent}) OR (categories.name <> 'For Rent' AND listings.price < {$sale}))",
            'no_size' => "(CASE WHEN property_types.name IN ('Land', 'Commercial') THEN COALESCE(property_attributes.lot_area, 0) < {$sqm} AND COALESCE(property_attributes.floor_area, 0) < {$sqm} ELSE COALESCE(property_attributes.floor_area, 0) < {$sqm} END)",
            'no_bedrooms' => "(property_types.name IN ('Condominium', 'House') AND property_subtypes.name <> 'Studio' AND COALESCE(property_attributes.bedroom_count, 0) < 1)",
            // A JSON column that is never NULL: a missing pin is {"lat": null, …} or a zero.
            'no_pin' => "(properties.geo_coordinates IS NULL OR JSON_VALID(properties.geo_coordinates) = 0 OR JSON_EXTRACT(properties.geo_coordinates, '$.lat') IS NULL OR JSON_EXTRACT(properties.geo_coordinates, '$.lat') = 0)",
            'no_area' => '(COALESCE(properties.geo_city_id, addr_b.city_id) IS NULL)',
        ];
    }

    private static function anyReasonSql(): string
    {
        return '('.implode(' OR ', self::reasonSql()).')';
    }

    private static function flagsSelectSql(): string
    {
        return implode(', ', array_map(fn ($k, $sql) => "CASE WHEN {$sql} THEN 1 ELSE 0 END as f_{$k}", array_keys(self::reasonSql()), self::reasonSql()));
    }
}
