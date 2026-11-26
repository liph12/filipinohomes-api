<?php

namespace App\Services\Listing;

use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public result pages, in three groups read one after the other:
 *
 *   1  available, with a price that can be shown
 *   2  available, but the price is not believable ("Price on request" until its agent confirms it)
 *   3  sold, rented or leased
 *
 * Inside a group the order is what it always was: `updated_at` newest first, then `id` so that equal
 * timestamps cannot repeat or skip a listing between pages. The hourly rotation that stamps `updated_at`
 * keeps deciding who leads; it now only leads among listings a buyer can act on.
 *
 * Why three queries rather than one with a computed sort key: no new column, no migration, and each group
 * is read through the existing (visibility, deleted_at, updated_at) index, in order, stopping after twelve
 * rows. The cost is one extra count query, which also tells us where each page starts. A page that falls
 * inside one group (nearly all of them) runs one page query; only the page that straddles a boundary runs two.
 *
 * `meta.total` is the same number it always was, so pagination links and page counts do not change.
 */
final class ListingFeed
{
    public const REAL = 1;

    public const ON_REQUEST = 2;

    public const CLOSED = 3;

    /** The index (visibility, deleted_at, updated_at) every group is read through; see its migration. */
    private const BROWSE_INDEX = 'listings_public_browse_idx';

    /** The request filters that make a query selective: a forced index would scan far for a rare match. */
    private const SELECTIVE = [
        'address', 'search', 'key_word', 'city_id', 'province_id', 'barangay_id', 'barangay', 'city', 'province',
        'type_id', 'type_str', 'subtype_str', 'subtypes', 'price_min', 'price_max', 'sqm_min', 'sqm_max',
        'beds', 'baths', 'furnishings', 'near_lat', 'verified_only', 'ats_verified',
    ];

    /**
     * @param  Builder<Listing>  $scoped  the public scope with the request's filters already applied
     * @param  list<string>|array<string, mixed>  $with  the relations the response reads
     */
    public static function paginate(Request $request, Builder $scoped, int $perPage, array $with): LengthAwarePaginator
    {
        $counts = self::counts($scoped);
        $total = array_sum($counts);
        $page = max(1, (int) LengthAwarePaginator::resolveCurrentPage());

        /** @var Collection<int, Listing> $items */
        $items = new Collection;
        $skip = ($page - 1) * $perPage;
        $room = $perPage;

        foreach ([self::REAL, self::ON_REQUEST, self::CLOSED] as $group) {
            $size = $counts[$group - 1];
            if ($skip >= $size) {
                $skip -= $size;

                continue;
            }

            $take = min($room, $size - $skip);
            $query = self::whereGroup(clone $scoped, $group)
                ->with($with)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->skip($skip)
                ->take($take);
            if (self::mayForceIndex($request)) {
                $query->forceIndex(self::BROWSE_INDEX);
            }

            $items = $items->concat($query->get());
            $room -= $take;
            $skip = 0;
            if ($room <= 0) {
                break;
            }
        }

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * How many listings each group holds in the filtered set, in one query.
     *
     * @param  Builder<Listing>  $scoped
     * @return array{0: int, 1: int, 2: int}
     */
    public static function counts(Builder $scoped): array
    {
        [$ok, $bindings] = self::believable();
        $active = self::activeSql();

        $row = (clone $scoped)->toBase()
            ->selectRaw(
                "SUM(CASE WHEN {$active} AND {$ok} THEN 1 ELSE 0 END) AS real_count,"
                ." SUM(CASE WHEN {$active} AND NOT {$ok} THEN 1 ELSE 0 END) AS request_count,"
                ." SUM(CASE WHEN NOT {$active} THEN 1 ELSE 0 END) AS closed_count",
                array_merge($bindings, $bindings),
            )
            ->first();

        return [(int) ($row->real_count ?? 0), (int) ($row->request_count ?? 0), (int) ($row->closed_count ?? 0)];
    }

    /**
     * Restrict a listings query to one group.
     *
     * @param  Builder<Listing>  $query
     * @return Builder<Listing>
     */
    public static function whereGroup(Builder $query, int $group): Builder
    {
        $isActive = fn ($property) => $property->where('status', 'active');

        if ($group === self::CLOSED) {
            return $query->whereDoesntHave('property', $isActive);
        }

        [$ok, $bindings] = self::believable();
        $query->whereHas('property', $isActive);

        return $group === self::REAL
            ? $query->whereRaw($ok, $bindings)
            : $query->whereRaw("NOT {$ok}", $bindings);
    }

    /** @return array{0: string, 1: list<mixed>} the "price can be shown" condition, with the confirmed prices */
    private static function believable(): array
    {
        return ListingPriceRules::believableSql(ListingFeedSwitch::approvedPrices());
    }

    private static function activeSql(): string
    {
        return 'EXISTS (SELECT 1 FROM properties WHERE properties.id = listings.property_id'
            ." AND properties.status = 'active' AND properties.deleted_at IS NULL)";
    }

    /** Only the plain browse (no selective filter) is sure to be fastest through the browse index, and only on MySQL. */
    private static function mayForceIndex(Request $request): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return ! $request->hasAny(self::SELECTIVE) && self::browseIndexExists();
    }

    /**
     * Whether this database has the index the group queries are written for. A host that has not run that
     * migration would otherwise throw on every result page ("Key ... doesn't exist in table"), so it simply
     * does not force the index. The answer only changes when a migration runs: it is looked up once an hour.
     */
    public static function browseIndexExists(): bool
    {
        try {
            return (bool) Cache::remember(
                'listings.browse_index_exists',
                3600,
                fn () => Schema::hasIndex('listings', self::BROWSE_INDEX),
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
