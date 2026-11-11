<?php

namespace Tests\Unit;

use App\Services\Listing\ListingPriceInsightsService;
use App\Services\Reports\Executive\PricePerSqm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The Executive Report's ₱/sqm must mean what the admin Price Insights' ₱/sqm
 * means. Those helpers are private there, so this reads them by reflection and
 * fails the moment the two definitions drift apart.
 */
class ExecutivePricePerSqmParityTest extends TestCase
{
    /** [type, floor area, lot area, price] */
    public static function listings(): array
    {
        return [
            'condo on its floor area' => ['Condominium', 50, 0, 5_000_000],
            'land on its lot area' => ['Land', 0, 200, 2_000_000],
            '"land" in any case, with spaces' => [' land ', 30, 400, 4_000_000],
            'a house ignores its lot' => ['House', 120, 300, 9_600_000],
            'just under the minimum' => ['Condominium', 9.99, 0, 1_000_000],
            'exactly the minimum' => ['Condominium', 10, 0, 1_000_000],
            'no area at all' => ['Commercial', null, null, 3_000_000],
            'numeric strings, as MySQL returns them' => ['Condominium', '45.50', '0.00', 4_550_000],
        ];
    }

    #[DataProvider('listings')]
    public function test_area_and_price_per_sqm_match_price_insights(string $type, mixed $floor, mixed $lot, float $price): void
    {
        $area = new ReflectionMethod(ListingPriceInsightsService::class, 'area');
        $perSqm = new ReflectionMethod(ListingPriceInsightsService::class, 'perSqm');

        $this->assertSame($area->invoke(null, $type, $floor, $lot), PricePerSqm::area($type, $floor, $lot));
        $this->assertSame($perSqm->invoke(null, $price, $type, $floor, $lot), PricePerSqm::perSqm($price, $type, $floor, $lot));
    }
}
