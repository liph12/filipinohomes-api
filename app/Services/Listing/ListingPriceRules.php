<?php

namespace App\Services\Listing;

/**
 * Which listings have a believable asking price, in one place for every admin
 * surface that quotes a "typical" price: Price Insights
 * ({@see ListingPriceInsightsService}) and the Listings Heatmap's area details
 * ({@see ListingHeatmapService::areaDetails()}). If they kept a copy each, the
 * two numbers for the same town would drift apart the day one of them changed.
 *
 * A believable price is on a PUBLIC listing that is not flagged, inside the
 * floor and ceiling of the listing's OWN category: For Rent from ₱1k to ₱10M a
 * month; For Sale and Foreclosure from ₱100k to ₱5B. The floors keep
 * placeholder prices (₱1, ₱999) out; the ceilings keep typos out — a ₱150B
 * house (CEB-16102) and an ₱84B one (NOR-15747) alone dragged the house
 * average to ₱60M, and the dearest real listing is under ₱1B.
 */
final class ListingPriceRules
{
    public const SALE_PRICE_FLOOR = 100_000;

    public const RENT_PRICE_FLOOR = 1_000;

    public const SALE_PRICE_CEILING = 5_000_000_000;

    public const RENT_PRICE_CEILING = 10_000_000;

    /**
     * Narrow a listings query to the listings with a believable price.
     *
     * The query must already join `categories` (every insights base query
     * does): each listing is held to its own category's floor and ceiling, so
     * "all categories" stays meaningful.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function apply($query)
    {
        return $query
            ->where('listings.visibility', 'public')
            ->where(fn ($q) => $q->whereNull('listings.verification_status')->orWhere('listings.verification_status', '!=', 'flagged'))
            ->whereNotNull('listings.price')
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('categories.name', 'For Rent')->whereBetween('listings.price', [self::RENT_PRICE_FLOOR, self::RENT_PRICE_CEILING]))
                ->orWhere(fn ($r) => $r->where('categories.name', '!=', 'For Rent')->whereBetween('listings.price', [self::SALE_PRICE_FLOOR, self::SALE_PRICE_CEILING])));
    }

    /**
     * The middle value; an even count averages the two middle values. Null
     * for no values. Order does not matter: the values are sorted here.
     *
     * @param  array<int, int|float>  $values
     */
    public static function median(array $values): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }

        sort($values);
        $values = array_values($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
