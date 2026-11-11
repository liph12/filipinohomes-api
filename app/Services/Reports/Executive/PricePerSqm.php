<?php

namespace App\Services\Reports\Executive;

use App\Services\Listing\ListingPriceRules;

/**
 * Price per square meter, defined exactly as the admin Price Insights define
 * it (ListingPriceInsightsService::area()/perSqm(), which are private there):
 *
 *   - priced on the LOT area for Land, the FLOOR area for everything else;
 *   - only when that area is at least MIN_AREA_SQM (a "1 sqm" placeholder
 *     would otherwise produce a ₱4.5M/sqm condo);
 *   - only for listings with a believable price for their category
 *     ({@see ListingPriceRules::apply()} — public, unflagged, inside the
 *     category's floor and ceiling).
 *
 * These are ASKING prices: FH records no closing price. The parity test
 * (ExecutivePricePerSqmParityTest) fails if the two definitions ever drift.
 */
final class PricePerSqm
{
    public const MIN_AREA_SQM = 10;

    /** The area a listing is priced on: lot area for land, floor area otherwise (0 when unknown). */
    public static function area(?string $type, mixed $floorArea, mixed $lotArea): float
    {
        return strcasecmp(trim((string) $type), 'Land') === 0 ? (float) ($lotArea ?? 0) : (float) ($floorArea ?? 0);
    }

    /** ₱ per sqm, or null when the area is too small to price on. */
    public static function perSqm(float $price, ?string $type, mixed $floorArea, mixed $lotArea): ?float
    {
        $area = self::area($type, $floorArea, $lotArea);

        return $area >= self::MIN_AREA_SQM ? $price / $area : null;
    }

    /** Median of a list of ₱/sqm values (null for none). */
    public static function median(array $values): ?float
    {
        return ListingPriceRules::median($values);
    }

    /**
     * Narrow a report listing query to the rows ₱/sqm may be taken over: the
     * believable-price rules plus a positive area. The query must already join
     * `categories`, `property_attributes as pa` and `property_types as pt`
     * (ReportQueries::listingBase() does).
     */
    public static function pricedRows($query)
    {
        return ListingPriceRules::apply($query)
            ->whereRaw('('.self::areaSql().') >= ?', [self::MIN_AREA_SQM]);
    }

    /** SQL for area() over the report's `pa` / `pt` aliases. */
    public static function areaSql(): string
    {
        return "CASE WHEN LOWER(TRIM(pt.name)) = 'land' THEN COALESCE(pa.lot_area, 0) ELSE COALESCE(pa.floor_area, 0) END";
    }
}
