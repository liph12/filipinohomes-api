<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Agent\AgentCachePurger;
use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * The agent's "your price looks wrong" prompt: list my listings that show "Price on request", confirm an unusual price,
 * correct a wrong one. Nothing changes a number on its own.
 */
class ListingPriceControllerTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.indexnow.enabled' => false, 'audit.enabled' => false]);
        $this->mock(AgentCachePurger::class)->shouldReceive('purgeAgent')->andReturnNull();

        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();
    }

    /** @return array{0: int, 1: User} an agent and their user, signed in */
    private function signedInAgent(): array
    {
        [$agent, $userId] = $this->feedAgent();
        $user = User::findOrFail($userId);
        Sanctum::actingAs($user);

        return [$agent, $user];
    }

    public function test_an_agent_sees_only_their_own_listings_that_show_price_on_request_and_why(): void
    {
        [$agent] = $this->signedInAgent();
        [$other] = $this->feedAgent();
        $junk = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);
        $lot = $this->feedListing(['agent' => $agent, 'price' => 700, 'category' => 1, 'type' => 'Land', 'lot' => 300]);
        $unusual = $this->feedListing(['agent' => $agent, 'price' => 85_000, 'category' => 1, 'type' => 'Land', 'lot' => 500]);
        $this->feedListing(['agent' => $agent, 'price' => 1_500_000]);                                         // fine
        $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'visibility' => 'private']);     // not public
        $this->feedListing(['agent' => $other, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);       // someone else's

        $rows = collect($this->getJson('/api/my-listings/price-issues')->assertOk()->assertJsonPath('meta.count', 3)->json('data'))->keyBy('id');

        $this->assertSame('thousands_shorthand', $rows[$junk]['price_reason']);
        $this->assertEquals(25_000, $rows[$junk]['price_suggested']);
        $this->assertFalse($rows[$junk]['price_confirmable']);
        $this->assertSame('per_sqm', $rows[$lot]['price_reason']);
        $this->assertEquals(210_000, $rows[$lot]['price_suggested']);
        $this->assertTrue($rows[$unusual]['price_confirmable']);
    }

    public function test_confirming_an_unusual_price_makes_it_show_as_a_price_and_only_for_that_figure(): void
    {
        [$agent] = $this->signedInAgent();
        $id = $this->feedListing(['agent' => $agent, 'price' => 85_000, 'category' => 1, 'type' => 'Land', 'lot' => 500]);

        $this->postJson("/api/listings/{$id}/price/confirm", ['price' => 85_000])->assertOk()->assertJsonPath('price_status', 'ok');
        $this->getJson('/api/my-listings/price-issues')->assertJsonPath('meta.count', 0);
        $this->assertSame([$id => 85_000.0], ListingFeedSwitch::approvedPrices());

        // The confirmation was for ₱85,000: change the price and it no longer counts.
        DB::table('listings')->where('id', $id)->update(['price' => 60_000]);
        $this->getJson('/api/my-listings/price-issues')->assertJsonPath('meta.count', 1);
    }

    public function test_a_price_that_can_never_be_right_cannot_be_confirmed_and_a_stale_figure_is_refused(): void
    {
        [$agent] = $this->signedInAgent();
        $junk = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);
        $unusual = $this->feedListing(['agent' => $agent, 'price' => 85_000, 'category' => 1, 'type' => 'Land', 'lot' => 500]);

        $this->postJson("/api/listings/{$junk}/price/confirm", ['price' => 25])
            ->assertStatus(422)->assertJsonPath('price_reason', 'thousands_shorthand')->assertJsonPath('price_suggested', 25000);
        $this->postJson("/api/listings/{$unusual}/price/confirm", ['price' => 90_000])->assertStatus(422); // not the figure on the listing
        $this->assertSame([], ListingFeedSwitch::approvedPrices());
    }

    public function test_correcting_a_price_updates_it_and_keeps_a_verified_listing_verified(): void
    {
        [$agent] = $this->signedInAgent();
        $junk = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium', 'verification' => 'verified']);
        $other = $this->feedListing(['agent' => $agent, 'price' => 1_500_000, 'verification' => 'verified']);

        $this->postJson("/api/listings/{$junk}/price/correct", ['price' => 25_000])->assertOk()->assertJsonPath('price_status', 'ok');

        $row = DB::table('listings')->where('id', $junk)->first();
        $this->assertEquals(25_000, $row->price);
        // A repair, not a re-pricing: the audit's verdict stands.
        $this->assertSame('verified', $row->verification_status);
        $this->getJson('/api/my-listings/price-issues')->assertJsonPath('meta.count', 0);

        // The ordinary price edit still sends a Verified listing back to review, as it always did.
        $this->putJson("/api/listings/{$other}", ['price' => 1_600_000])->assertOk();
        $this->assertSame('pending_review', DB::table('listings')->where('id', $other)->value('verification_status'));
    }

    public function test_a_price_that_is_not_flagged_cannot_be_changed_through_the_repair_and_skip_the_review(): void
    {
        [$agent] = $this->signedInAgent();
        $id = $this->feedListing(['agent' => $agent, 'price' => 1_500_000, 'verification' => 'verified']);

        // The repair is for a flagged price. A fine price changed here would skip the review the edit form triggers.
        $this->postJson("/api/listings/{$id}/price/correct", ['price' => 2_000_000])
            ->assertStatus(422)->assertJsonPath('price_status', 'ok');

        $row = DB::table('listings')->where('id', $id)->first();
        $this->assertEquals(1_500_000, $row->price);
        $this->assertSame('verified', $row->verification_status);

        // The ordinary edit is the way, and it does reopen the review.
        $this->putJson("/api/listings/{$id}", ['price' => 2_000_000])->assertOk();
        $this->assertSame('pending_review', DB::table('listings')->where('id', $id)->value('verification_status'));
    }

    public function test_confirming_says_so_when_the_list_of_confirmed_prices_is_full(): void
    {
        config(['listings.max_approved' => 1]);
        [$agent] = $this->signedInAgent();
        $first = $this->feedListing(['agent' => $agent, 'price' => 85_000, 'category' => 1, 'type' => 'Land', 'lot' => 500]);
        $second = $this->feedListing(['agent' => $agent, 'price' => 90_000, 'category' => 1, 'type' => 'Land', 'lot' => 500]);

        $this->postJson("/api/listings/{$first}/price/confirm", ['price' => 85_000])->assertOk();
        $this->postJson("/api/listings/{$second}/price/confirm", ['price' => 90_000])->assertStatus(422);

        $this->assertSame([$first => 85_000.0], ListingFeedSwitch::approvedPrices());
    }

    public function test_a_correction_that_still_looks_wrong_is_refused_with_a_hint(): void
    {
        [$agent] = $this->signedInAgent();
        $id = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);

        $this->postJson("/api/listings/{$id}/price/correct", ['price' => 30])
            ->assertStatus(422)->assertJsonPath('price_reason', 'thousands_shorthand')->assertJsonPath('price_suggested', 30000);
        $this->postJson("/api/listings/{$id}/price/correct", ['price' => 0])->assertStatus(422);
        $this->assertEquals(25, DB::table('listings')->where('id', $id)->value('price'));
    }

    public function test_an_agent_cannot_touch_someone_elses_listing_but_an_admin_can(): void
    {
        $this->signedInAgent();
        [$other] = $this->feedAgent();
        $id = $this->feedListing(['agent' => $other, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);

        $this->postJson("/api/listings/{$id}/price/correct", ['price' => 25_000])->assertForbidden();
        $this->postJson("/api/listings/{$id}/price/confirm", ['price' => 25])->assertForbidden();

        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'role_id' => 1]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/listings/{$id}/price/correct", ['price' => 25_000])->assertOk();
        // An admin can also read an agent's list.
        $this->feedListing(['agent' => $other, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);
        $this->getJson("/api/my-listings/price-issues?agent_id={$other}")->assertOk()->assertJsonPath('meta.count', 1);
    }

    public function test_the_quick_edit_follows_the_validation_switch(): void
    {
        [$agent] = $this->signedInAgent();
        $id = $this->feedListing(['agent' => $agent, 'price' => 1_500_000, 'category' => 2, 'type' => 'Condominium']);

        // log (the default): the implausible price goes through, as it always did.
        $this->putJson("/api/listings/{$id}", ['price' => 25])->assertOk();
        $this->assertEquals(25, DB::table('listings')->where('id', $id)->value('price'));

        // enforce: refused, with the likely figure.
        DB::table('listings')->where('id', $id)->update(['price' => 1_500_000]);
        ListingFeedSwitch::set(ListingFeedSwitch::VALIDATION, 'enforce');
        $this->putJson("/api/listings/{$id}", ['price' => 25])->assertStatus(422)->assertJsonValidationErrors('price');
        $this->putJson("/api/listings/{$id}", ['price' => 25_000])->assertOk();
    }
}
