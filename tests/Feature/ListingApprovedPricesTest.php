<?php

namespace Tests\Feature;

use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * The prices agents confirmed as right live in one settings value. Two agents confirming at once must not lose each
 * other's entry, the value must stay small enough to fit its column and to ride along in every price query, and a
 * confirmation that can never count again (the listing is gone, or its price moved) must not take room.
 */
class ListingApprovedPricesTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();
    }

    /** A lot whose price is unusual but possible: the kind an agent can confirm. */
    private function unusual(int $price = 85_000): int
    {
        return $this->feedListing(['price' => $price, 'category' => 1, 'type' => 'Land', 'lot' => 500]);
    }

    public function test_it_stores_and_takes_back_a_confirmation(): void
    {
        $id = $this->unusual();

        $this->assertTrue(ListingFeedSwitch::approve($id, 85_000));
        $this->assertSame([$id => 85_000.0], ListingFeedSwitch::approvedPrices());

        ListingFeedSwitch::revoke($id);
        $this->assertSame([], ListingFeedSwitch::approvedPrices());

        ListingFeedSwitch::revoke($id); // nothing left to take out: no error, nothing written
        $this->assertSame([], ListingFeedSwitch::approvedPrices());
    }

    public function test_a_confirmation_made_elsewhere_in_the_meantime_is_not_lost(): void
    {
        $mine = $this->unusual(85_000);
        $theirs = $this->unusual(90_000);

        // This process has already read the (empty) list...
        $this->assertSame([], ListingFeedSwitch::approvedPrices());
        // ...when another worker confirms a different listing.
        DB::table('settings')->insert(['key' => ListingFeedSwitch::APPROVED, 'value' => json_encode([$theirs => 90_000])]);

        ListingFeedSwitch::approve($mine, 85_000);

        $this->assertEquals([$theirs => 90_000.0, $mine => 85_000.0], ListingFeedSwitch::approvedPrices());
    }

    public function test_confirmations_that_can_never_count_again_are_dropped_when_the_next_one_is_written(): void
    {
        $gone = $this->unusual(85_000);
        $moved = $this->unusual(86_000);
        $kept = $this->unusual(87_000);
        ListingFeedSwitch::approve($gone, 85_000);
        ListingFeedSwitch::approve($moved, 86_000);
        ListingFeedSwitch::approve($kept, 87_000);

        DB::table('listings')->where('id', $gone)->update(['deleted_at' => now()]);
        DB::table('listings')->where('id', $moved)->update(['price' => 99_000]);
        $next = $this->unusual(88_000);
        ListingFeedSwitch::approve($next, 88_000);

        $this->assertEquals([$kept => 87_000.0, $next => 88_000.0], ListingFeedSwitch::approvedPrices());
    }

    public function test_the_list_has_a_ceiling_and_a_listing_already_on_it_can_still_be_updated(): void
    {
        config(['listings.max_approved' => 2]);
        $a = $this->unusual(85_000);
        $b = $this->unusual(86_000);
        $c = $this->unusual(87_000);

        $this->assertTrue(ListingFeedSwitch::approve($a, 85_000));
        $this->assertTrue(ListingFeedSwitch::approve($b, 86_000));
        $this->assertFalse(ListingFeedSwitch::approve($c, 87_000)); // full
        $this->assertTrue(ListingFeedSwitch::approve($a, 85_000));  // already on it: no new room needed

        $this->assertEquals([$a => 85_000.0, $b => 86_000.0], ListingFeedSwitch::approvedPrices());

        // A confirmation that no longer counts makes room.
        DB::table('listings')->where('id', $b)->update(['price' => 99_000]);
        $this->assertTrue(ListingFeedSwitch::approve($c, 87_000));
        $this->assertEquals([$a => 85_000.0, $c => 87_000.0], ListingFeedSwitch::approvedPrices());
    }

    public function test_the_decoded_list_is_reused_until_the_settings_are_read_again(): void
    {
        $id = $this->unusual();
        ListingFeedSwitch::approve($id, 85_000);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        for ($i = 0; $i < 25; $i++) { // the resource asks once per listing on a page
            ListingFeedSwitch::approvedPrices();
        }

        $this->assertLessThanOrEqual(1, $queries);
    }
}
