<?php

namespace Tests\Feature;

use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/** `php artisan listings:switch`: the safe way to flip the order / price switches. */
class ListingsSwitchCommandTest extends TestCase
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

    public function test_every_switch_starts_at_the_behaviour_from_before_it_existed(): void
    {
        $this->assertSame('legacy', ListingFeedSwitch::order());
        $this->assertFalse(ListingFeedSwitch::priceGuard());
        $this->assertSame('log', ListingFeedSwitch::priceValidation());
        $this->assertSame('id', ListingFeedSwitch::rotationPicker());
        $this->assertFalse(ListingFeedSwitch::sitemapLastmod());
        $this->assertSame([100, 2, 4, 45], [
            ListingFeedSwitch::dial('batch'), ListingFeedSwitch::dial('incomplete_every'),
            ListingFeedSwitch::dial('heldback_every'), ListingFeedSwitch::dial('agent_idle_days'),
        ]);
        $this->assertSame([], ListingFeedSwitch::approvedPrices());
    }

    public function test_it_shows_changes_and_undoes_a_switch(): void
    {
        $this->artisan('listings:switch')->assertSuccessful()->expectsOutputToContain('legacy  (default)');

        $this->artisan('listings:switch', ['name' => 'order', 'value' => 'groups'])->assertSuccessful();
        $this->assertSame('groups', ListingFeedSwitch::order());
        $this->artisan('listings:switch', ['name' => 'price-guard', 'value' => 'on'])->assertSuccessful();
        $this->assertTrue(ListingFeedSwitch::priceGuard());
        $this->artisan('listings:switch', ['name' => 'validation', 'value' => 'enforce'])->assertSuccessful();
        $this->assertSame('enforce', ListingFeedSwitch::priceValidation());
        $this->artisan('listings:switch', ['name' => 'picker', 'value' => 'fair'])->assertSuccessful();
        $this->assertSame('fair', ListingFeedSwitch::rotationPicker());
        $this->artisan('listings:switch', ['name' => 'lastmod', 'value' => 'on'])->assertSuccessful();
        $this->assertTrue(ListingFeedSwitch::sitemapLastmod());

        // Back to the defaults: exactly the old behaviour again.
        $this->artisan('listings:switch', ['name' => 'order', 'value' => 'legacy'])->assertSuccessful();
        $this->assertSame('legacy', ListingFeedSwitch::order());
    }

    public function test_it_refuses_a_value_it_does_not_know_and_changes_nothing(): void
    {
        $this->artisan('listings:switch', ['name' => 'order', 'value' => 'random'])->assertFailed();
        $this->artisan('listings:switch', ['name' => 'nonsense', 'value' => 'on'])->assertFailed();
        $this->artisan('listings:switch', ['name' => 'dial', 'value' => 'batch', 'extra' => '0'])->assertFailed();

        $this->assertSame('legacy', ListingFeedSwitch::order());
        $this->assertSame(100, ListingFeedSwitch::dial('batch'));
        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_a_dial_can_be_changed(): void
    {
        $this->artisan('listings:switch', ['name' => 'dial', 'value' => 'incomplete_every', 'extra' => '3'])->assertSuccessful();

        $this->assertSame(3, ListingFeedSwitch::dial('incomplete_every'));
    }

    public function test_a_garbled_setting_falls_back_to_the_default(): void
    {
        DB::table('settings')->insert([
            ['key' => ListingFeedSwitch::ORDER, 'value' => 'banana'],
            ['key' => ListingFeedSwitch::GUARD, 'value' => 'maybe'],
            ['key' => 'listings.rotation.batch', 'value' => '-5'],
            ['key' => ListingFeedSwitch::APPROVED, 'value' => 'not json'],
        ]);

        $this->assertSame('legacy', ListingFeedSwitch::order());
        $this->assertFalse(ListingFeedSwitch::priceGuard());
        $this->assertSame(100, ListingFeedSwitch::dial('batch'));
        $this->assertSame([], ListingFeedSwitch::approvedPrices());
    }
}
