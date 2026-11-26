<?php

namespace Tests\Feature;

use App\Services\Listing\ListingFeedSwitch;
use App\Services\Listing\ListingPriceRules;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * Which prices can be shown as an asking price, and why not. The classifier (PHP) and its SQL twin (the result pages
 * and sitemap counts) must agree on every row: a page that says "Price on request" must be the page the filters leave out.
 */
class ListingPriceClassifierTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();
    }

    /** @return array<string, array{0: mixed, 1: string, 2: string|null, 3: mixed, 4: string, 5: string|null, 6: float|null, 7: bool}> */
    public static function cases(): array
    {
        return [
            // price, category, type, lot area, status, reason, suggested, confirmable
            'rent in band' => [25_000, 'For Rent', 'House', null, 'ok', null, null, false],
            'rent at the floor' => [1_000, 'For Rent', 'Condominium', null, 'ok', null, null, false],
            'rent typed in thousands (condo)' => [25, 'For Rent', 'Condominium', null, 'suspect', 'thousands_shorthand', 25_000.0, false],
            'rent typed in thousands (house)' => [99, 'For Rent', 'House', null, 'suspect', 'thousands_shorthand', 99_000.0, false],
            'a lot rented for 40' => [40, 'For Rent', 'Land', null, 'suspect', 'too_low', null, false],
            'rent between 100 and 999' => [110, 'For Rent', 'Condominium', null, 'suspect', 'too_low', null, false],
            'rent of 4 is not shorthand' => [4, 'For Rent', 'House', null, 'suspect', 'too_low', null, false],
            'rent over ten million' => [68_000_000, 'For Rent', 'House', null, 'suspect', 'too_high', null, true],
            'zero price' => [0, 'For Rent', 'House', null, 'suspect', 'no_price', null, false],
            'no price at all' => [null, 'For Sale', 'House', null, 'suspect', 'no_price', null, false],
            'sale in band' => [1_350_000, 'For Sale', 'House', null, 'ok', null, null, false],
            'sale at the floor' => [100_000, 'For Sale', 'Land', null, 'ok', null, null, false],
            'foreclosed in band' => [2_000_000, 'Foreclosed', 'House', null, 'ok', null, null, false],
            'sale typed in millions' => [3, 'For Sale', 'House', null, 'suspect', 'millions_shorthand', 3_000_000.0, false],
            'a placeholder 1' => [1, 'For Sale', 'House', null, 'suspect', 'too_low', null, false],
            'per sqm on a lot' => [700, 'For Sale', 'Land', 300, 'suspect', 'per_sqm', 210_000.0, false],
            'per sqm needs the lot area' => [700, 'For Sale', 'Land', null, 'suspect', 'too_low', null, false],
            'per sqm that is not a lot' => [700, 'For Sale', 'Commercial', 300, 'suspect', 'too_low', null, false],
            'per sqm whose total is still tiny' => [15, 'For Sale', 'Land', 300, 'suspect', 'too_low', null, false],
            'an unusual small lot can be confirmed' => [85_000, 'For Sale', 'Land', 500, 'suspect', 'too_low', null, true],
            'an unusual price under the hard floor cannot' => [9_000, 'For Sale', 'House', null, 'suspect', 'too_low', null, false],
            'sale over five billion' => [6_000_000_000, 'For Sale', 'Commercial', null, 'suspect', 'too_high', null, true],
            'assume cash-out in band' => [550_000, 'Assume Balance', 'Condominium', null, 'ok', null, null, false],
            'assume cash-out may be small' => [5_000, 'Assume Balance', 'Condominium', null, 'ok', null, null, false],
            'assume cash-out under the floor' => [800, 'Assume Balance', 'Condominium', null, 'suspect', 'too_low', null, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_classifier(mixed $price, string $category, ?string $type, mixed $lot, string $status, ?string $reason, ?float $suggested, bool $confirmable): void
    {
        $v = ListingPriceRules::classify($price, $category, $type, $lot);

        $this->assertSame($status, $v['status']);
        $this->assertSame($reason, $v['reason']);
        $this->assertSame($suggested, $v['suggested']);
        $this->assertSame($confirmable, $v['confirmable']);
    }

    public function test_a_confirmed_price_is_ok_for_that_figure(): void
    {
        $this->assertSame('suspect', ListingPriceRules::classify(85_000, 'For Sale', 'Land', 500)['status']);
        $this->assertSame('ok', ListingPriceRules::classify(85_000, 'For Sale', 'Land', 500, approved: true)['status']);
        // ...but nothing makes a zero price believable.
        $this->assertSame('suspect', ListingPriceRules::classify(0, 'For Sale', 'Land', 500, approved: true)['status']);
    }

    public function test_the_category_kinds_follow_the_names(): void
    {
        $this->assertSame('rent', ListingPriceRules::kindOf('For Rent'));
        $this->assertSame('assume', ListingPriceRules::kindOf('Assume Balance'));
        $this->assertSame('assume', ListingPriceRules::kindOf('For Assume')); // the name before the 2026-11-14 rename
        $this->assertSame('sale', ListingPriceRules::kindOf('For Sale'));
        $this->assertSame('sale', ListingPriceRules::kindOf('Foreclosed'));
        $this->assertSame('sale', ListingPriceRules::kindOf(null));
    }

    public function test_the_sql_twin_agrees_with_the_classifier_on_every_row(): void
    {
        // Every case above becomes a listing; the SQL twin must pick out exactly the classifier's "ok" ones.
        $categoryIds = ['For Sale' => 1, 'For Rent' => 2, 'Foreclosed' => 3, 'Assume Balance' => 4];
        $expectedOk = [];
        foreach (self::cases() as $label => [$price, $category, $type, $lot, $status]) {
            $id = $this->feedListing(['price' => $price, 'category' => $categoryIds[$category], 'type' => $type ?? 'House', 'lot' => $lot, 'name' => $label]);
            if ($status === 'ok') {
                $expectedOk[] = $id;
            }
        }

        [$sql, $bindings] = ListingPriceRules::believableSql();
        $sqlOk = DB::table('listings')->whereRaw($sql, $bindings)->orderBy('id')->pluck('id')->all();

        $this->assertSame($expectedOk, array_map('intval', $sqlOk));
        // A listing with no price is "not believable", never a NULL that NOT() would also leave out.
        $noPrice = DB::table('listings')->where('name', 'no price at all')->value('id');
        $this->assertNotContains($noPrice, $sqlOk);
        $this->assertContains($noPrice, DB::table('listings')->whereRaw("NOT {$sql}", $bindings)->pluck('id')->all());
    }

    public function test_a_confirmation_makes_the_sql_twin_agree_too(): void
    {
        $id = $this->feedListing(['price' => 85_000, 'category' => 1, 'type' => 'Land', 'lot' => 500]);
        [$sql, $bindings] = ListingPriceRules::believableSql();
        $this->assertNotContains($id, DB::table('listings')->whereRaw($sql, $bindings)->pluck('id')->all());

        ListingFeedSwitch::approve($id, 85_000);
        [$sql, $bindings] = ListingPriceRules::believableSql(ListingFeedSwitch::approvedPrices());
        $this->assertContains($id, DB::table('listings')->whereRaw($sql, $bindings)->pluck('id')->all());

        // The confirmation is for that exact figure: change the price and it no longer counts.
        DB::table('listings')->where('id', $id)->update(['price' => 60_000]);
        $this->assertNotContains($id, DB::table('listings')->whereRaw($sql, $bindings)->pluck('id')->all());
    }
}
