<?php

namespace App\Services\Listing;

use App\Models\Category;

/**
 * Which listings have a believable asking price, in one place for every admin
 * surface that quotes a "typical" price: Price Insights
 * ({@see ListingPriceInsightsService}) and the Listings Heatmap's area details
 * ({@see ListingHeatmapService::areaDetails()}). If they kept a copy each, the
 * two numbers for the same town would drift apart the day one of them changed.
 *
 * A believable price is on a PUBLIC listing that is not flagged, inside the
 * floor and ceiling of the listing's OWN category: For Rent from ₱1k to ₱10M a
 * month; For Sale and Foreclosed from ₱100k to ₱5B. The floors keep
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
     * Version of the classification below; bumped when a rule changes so a stored
     * verdict can tell which rules produced it.
     */
    public const VERSION = 1;

    /**
     * Prices nobody can confirm as right: a month's rent under ₱1,000, a sale under ₱10,000,
     * an Assume cash-out under ₱1,000. Between these and the band above, a price is only
     * "unusual", and its agent may confirm it.
     */
    public const RENT_HARD_FLOOR = 1_000;

    public const SALE_HARD_FLOOR = 10_000;

    public const ASSUME_FLOOR = 1_000;

    public const REASON_NO_PRICE = 'no_price';

    public const REASON_THOUSANDS = 'thousands_shorthand';

    public const REASON_MILLIONS = 'millions_shorthand';

    public const REASON_PER_SQM = 'per_sqm';

    public const REASON_TOO_LOW = 'too_low';

    public const REASON_TOO_HIGH = 'too_high';

    /** @var array{rent: list<int>, assume: list<int>}|null */
    private static ?array $kindIds = null;

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

    /** Which rule a category follows: 'rent', 'assume' (the price is only the cash-out) or 'sale' (sale and foreclosed). */
    public static function kindOf(?string $categoryName): string
    {
        $name = strtolower(trim((string) $categoryName));

        return match (true) {
            str_contains($name, 'rent') => 'rent',
            str_contains($name, 'assume') => 'assume',
            default => 'sale',
        };
    }

    /**
     * Can this price be shown as the asking price? The number is never changed: a suspect
     * price reads "Price on request" until its agent confirms or corrects it.
     *
     * ok            inside the category's band (above), or confirmed by the agent for this exact figure
     * thousands     a rent of 5-99 on a house or condo: 25 for ₱25,000 a month (suggests ×1,000)
     * millions      a sale of 2-99 on a house or condo: 3 for ₱3,000,000 (suggests ×1,000,000)
     * per sqm       a lot under ₱10,000 whose lot area times it lands inside the band (suggests the product)
     * too low/high  anything else outside the band; the agent may confirm it when it is above the hard floor
     * no price      empty or zero
     *
     * Pure: the same inputs always give the same answer. {@see believableSql()} is its twin for queries
     * and the two agree on ok / suspect (a test checks them row by row).
     *
     * @return array{status: 'ok'|'suspect', reason: string|null, suggested: float|null, confirmable: bool}
     */
    public static function classify(
        mixed $price,
        ?string $categoryName,
        ?string $typeName = null,
        mixed $lotArea = null,
        bool $approved = false,
    ): array {
        $p = is_numeric($price) ? (float) $price : 0.0;
        $ok = ['status' => 'ok', 'reason' => null, 'suggested' => null, 'confirmable' => false];
        $suspect = fn (string $reason, ?float $suggested = null, bool $confirmable = false): array => [
            'status' => 'suspect', 'reason' => $reason, 'suggested' => $suggested, 'confirmable' => $confirmable,
        ];

        if ($p <= 0) {
            return $suspect(self::REASON_NO_PRICE);
        }
        if ($approved) {
            return $ok;
        }

        $kind = self::kindOf($categoryName);
        $type = strtolower(trim((string) $typeName));
        $isHome = in_array($type, ['house', 'condominium'], true);
        $lot = is_numeric($lotArea) ? (float) $lotArea : 0.0;

        if ($kind === 'rent') {
            if ($p >= self::RENT_PRICE_FLOOR && $p <= self::RENT_PRICE_CEILING) {
                return $ok;
            }
            if ($p > self::RENT_PRICE_CEILING) {
                return $suspect(self::REASON_TOO_HIGH, null, true);
            }

            return $isHome && $p >= 5 && $p <= 99
                ? $suspect(self::REASON_THOUSANDS, $p * 1_000)
                : $suspect(self::REASON_TOO_LOW);
        }

        if ($kind === 'assume') {
            if ($p >= self::ASSUME_FLOOR && $p <= self::SALE_PRICE_CEILING) {
                return $ok;
            }

            return $p > self::SALE_PRICE_CEILING
                ? $suspect(self::REASON_TOO_HIGH, null, true)
                : $suspect(self::REASON_TOO_LOW);
        }

        if ($p >= self::SALE_PRICE_FLOOR && $p <= self::SALE_PRICE_CEILING) {
            return $ok;
        }
        if ($p > self::SALE_PRICE_CEILING) {
            return $suspect(self::REASON_TOO_HIGH, null, true);
        }

        // A sale under ₱100,000.
        $total = $p * $lot;
        if ($type === 'land' && $lot >= 10 && $p >= 100 && $p < self::SALE_HARD_FLOOR
            && $total >= self::SALE_PRICE_FLOOR && $total <= self::SALE_PRICE_CEILING) {
            return $suspect(self::REASON_PER_SQM, round($total, 2));
        }
        if ($isHome && $p >= 2 && $p <= 99) {
            return $suspect(self::REASON_MILLIONS, $p * 1_000_000);
        }

        return $suspect(self::REASON_TOO_LOW, null, $p >= self::SALE_HARD_FLOOR);
    }

    /** The ids of the rent and assume categories, by name (so a renamed or added category needs no code change). */
    public static function categoryIds(): array
    {
        if (self::$kindIds === null) {
            $ids = ['rent' => [], 'assume' => []];
            foreach (Category::query()->get(['id', 'name']) as $category) {
                $kind = self::kindOf($category->name);
                if ($kind !== 'sale') {
                    $ids[$kind][] = (int) $category->id;
                }
            }
            self::$kindIds = $ids;
        }

        return self::$kindIds;
    }

    /** Forget the memoised category ids (tests, and anything that adds a category). */
    public static function flush(): void
    {
        self::$kindIds = null;
    }

    /**
     * The same "can this price be shown" rule as a query condition on the listings table:
     * inside the category's band, or confirmed for exactly this figure. Returns the SQL and its
     * bindings, to be used with whereRaw / selectRaw.
     *
     * @param  array<int, float|int|string>  $approved  listing id => the price its agent confirmed
     * @return array{0: string, 1: list<mixed>}
     */
    public static function believableSql(array $approved = [], string $table = 'listings'): array
    {
        $ids = self::categoryIds();
        $inList = fn (array $values): string => $values === [] ? '0' : implode(',', array_map('intval', $values));
        $rent = $inList($ids['rent']);
        $assume = $inList($ids['assume']);
        $notSale = $inList(array_merge($ids['rent'], $ids['assume']));

        // COALESCE: a listing with no price is "not believable", never a NULL that NOT() would also leave out.
        $price = "COALESCE({$table}.price, 0)";
        $sql = "(({$table}.category_id IN ({$rent}) AND {$price} BETWEEN ? AND ?)"
            ." OR ({$table}.category_id IN ({$assume}) AND {$price} BETWEEN ? AND ?)"
            ." OR ({$table}.category_id NOT IN ({$notSale}) AND {$price} BETWEEN ? AND ?)";
        $bindings = [
            self::RENT_PRICE_FLOOR, self::RENT_PRICE_CEILING,
            self::ASSUME_FLOOR, self::SALE_PRICE_CEILING,
            self::SALE_PRICE_FLOOR, self::SALE_PRICE_CEILING,
        ];

        foreach ($approved as $id => $price) {
            $sql .= " OR ({$table}.id = ? AND {$table}.price = ?)";
            $bindings[] = (int) $id;
            $bindings[] = (float) $price;
        }

        return [$sql.')', $bindings];
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

    /**
     * The value a fraction $p (0–1) of the way up the sorted values, by linear
     * interpolation between the two nearest — so percentile($v, 0.5) equals
     * median($v), and 0.25 / 0.75 bound the middle half. Null for no values.
     *
     * @param  array<int, int|float>  $values
     */
    public static function percentile(array $values, float $p): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }

        sort($values);
        $values = array_values($values);
        $rank = max(0.0, min(1.0, $p)) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);

        return (float) ($values[$lo] + ($values[$hi] - $values[$lo]) * ($rank - $lo));
    }
}
