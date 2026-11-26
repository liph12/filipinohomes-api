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
 * The public result pages (GET /api/listings): the order they come in, and that the new ordering changes nothing
 * until its switch is turned on.
 */
class ListingFeedTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.guest_api_secret' => 'guest-secret', 'services.indexnow.enabled' => false, 'audit.enabled' => false]);
        $this->mock(AgentCachePurger::class)->shouldReceive('purgeAgent')->andReturnNull();

        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();
        DB::table('login_logs')->count(); // the table the agent resource reads
    }

    private function guest(): static
    {
        $payload = json_encode(['exp' => time() + 600]);

        return $this->withHeader('X-Guest-Token', base64_encode($payload).'.'.hash_hmac('sha256', $payload, 'guest-secret'));
    }

    /** @return list<int> the ids on a page of the public listings, in order */
    private function ids(string $query = ''): array
    {
        return collect($this->guest()->getJson('/api/listings'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    private function switchOn(string $key = ListingFeedSwitch::ORDER, string $value = 'groups'): void
    {
        ListingFeedSwitch::set($key, $value);
    }

    /**
     * 30 listings whose update times run opposite to how the groups should come out: the closed ones are the newest,
     * then the "Price on request" ones, then the ordinary ones.
     *
     * @return array{real: list<int>, request: list<int>, closed: list<int>}
     */
    private function seedThreeGroups(): array
    {
        [$agent] = $this->feedAgent();
        $ids = ['real' => [], 'request' => [], 'closed' => []];
        for ($i = 1; $i <= 20; $i++) { // oldest
            $ids['real'][] = $this->feedListing(['agent' => $agent, 'price' => 25_000 + $i, 'category' => 2, 'updated' => now()->subDays(100 + $i)]);
        }
        for ($i = 1; $i <= 6; $i++) {
            $ids['request'][] = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium', 'updated' => now()->subDays(50 + $i)]);
        }
        for ($i = 1; $i <= 4; $i++) { // newest
            $ids['closed'][] = $this->feedListing(['agent' => $agent, 'price' => 30_000, 'category' => 2, 'status' => 'rented', 'updated' => now()->subDays($i)]);
        }

        return $ids;
    }

    public function test_the_default_order_is_still_newest_update_first(): void
    {
        $ids = $this->seedThreeGroups();

        $page = $this->ids();

        // The closed listings are the newest, so with the switch off they lead, exactly as before.
        $this->assertSame($ids['closed'], array_slice($page, 0, 4));
    }

    public function test_the_groups_come_in_order_across_pages_and_the_total_does_not_change(): void
    {
        $ids = $this->seedThreeGroups();
        $legacyTotal = $this->guest()->getJson('/api/listings')->json('meta.total');

        $this->switchOn();

        // Inside a group: newest update first (and the first id listed is the newest listing of that group).
        $real = array_reverse($ids['real']);        // updated = now - (100 + i): the smallest i is newest
        $real = $ids['real'];                       // i = 1 is the newest
        $request = $ids['request'];
        $closed = $ids['closed'];

        $page1 = $this->guest()->getJson('/api/listings')->assertOk();
        $this->assertSame(array_slice($real, 0, 12), collect($page1->json('data'))->pluck('id')->all());
        $this->assertSame($legacyTotal, $page1->json('meta.total'));
        $this->assertSame(30, $page1->json('meta.total'));
        $this->assertSame(3, $page1->json('meta.last_page'));

        // Page 2 straddles the first boundary: the last 8 ordinary listings, then the first 4 "Price on request".
        $this->assertSame([...array_slice($real, 12, 8), ...array_slice($request, 0, 4)], $this->ids('?page=2'));
        // Page 3 straddles the second: the last 2 "Price on request", then the 4 closed ones.
        $this->assertSame([...array_slice($request, 4, 2), ...$closed], $this->ids('?page=3'));

        // Nothing repeated, nothing lost.
        $all = [...$this->ids(), ...$this->ids('?page=2'), ...$this->ids('?page=3')];
        $this->assertCount(30, array_unique($all));
    }

    public function test_equal_timestamps_are_ordered_by_id_so_pages_never_repeat_or_skip(): void
    {
        [$agent] = $this->feedAgent();
        $same = now()->subDay();
        $ids = [];
        for ($i = 0; $i < 15; $i++) {
            $ids[] = $this->feedListing(['agent' => $agent, 'updated' => $same]);
        }
        $this->switchOn();

        $all = [...$this->ids(), ...$this->ids('?page=2')];

        $this->assertSame(array_reverse($ids), $all); // newest id first
    }

    public function test_the_rails_and_an_agents_own_page_keep_the_newest_update_order(): void
    {
        $ids = $this->seedThreeGroups();
        $this->switchOn();

        // `pool` is what the detail-page rails send; they pick their own listings from the pool.
        $this->assertSame($ids['closed'], array_slice($this->ids('?pool=30'), 0, 4));
        // An agent's own website page keeps showing everything they made public, newest update first.
        $agent = DB::table('listings')->where('id', $ids['real'][0])->value('agent_id');
        $this->assertSame($ids['closed'], array_slice($this->ids("?agent_id={$agent}"), 0, 4));
    }

    public function test_a_listing_with_no_price_lands_in_price_on_request_instead_of_vanishing(): void
    {
        [$agent] = $this->feedAgent();
        $ordinary = $this->feedListing(['agent' => $agent, 'price' => 1_500_000, 'updated' => now()->subDays(5)]);
        $noPrice = $this->feedListing(['agent' => $agent, 'price' => null, 'updated' => now()->subDay()]);
        $this->switchOn();

        $this->assertSame([$ordinary, $noPrice], $this->ids());
        $this->assertSame(2, $this->guest()->getJson('/api/listings')->json('meta.total'));
    }

    public function test_a_confirmed_price_moves_back_into_the_ordinary_group(): void
    {
        [$agent] = $this->feedAgent();
        $unusual = $this->feedListing(['agent' => $agent, 'price' => 85_000, 'type' => 'Land', 'lot' => 500, 'updated' => now()->subDay()]);
        $ordinary = $this->feedListing(['agent' => $agent, 'price' => 1_500_000, 'updated' => now()->subDays(9)]);
        $this->switchOn();

        $this->assertSame([$ordinary, $unusual], $this->ids());

        ListingFeedSwitch::approve($unusual, 85_000);

        $this->assertSame([$unusual, $ordinary], $this->ids());
    }

    public function test_the_price_verdict_appears_only_while_the_guard_is_on_and_the_reason_only_for_the_owner(): void
    {
        [$agent, $userId] = $this->feedAgent();
        $junk = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);

        // Off: the field is not there at all, so nothing changes for the frontend.
        $row = $this->guest()->getJson('/api/listings')->json('data.0');
        $this->assertArrayNotHasKey('price_status', $row);

        $this->switchOn(ListingFeedSwitch::GUARD, 'on');

        $row = $this->guest()->getJson('/api/listings')->json('data.0');
        $this->assertSame('suspect', $row['price_status']);
        $this->assertArrayNotHasKey('price_reason', $row); // a visitor is not told why

        Sanctum::actingAs(User::findOrFail($userId));
        $row = $this->guest()->getJson('/api/listings')->json('data.0');
        $this->assertSame('thousands_shorthand', $row['price_reason']);
        $this->assertEquals(25_000, $row['price_suggested']);
        $this->assertFalse($row['price_confirmable']);
        $this->assertSame($junk, $row['id']);
    }

    public function test_price_filters_leave_out_a_suspect_price_only_when_the_guard_is_on(): void
    {
        [$agent] = $this->feedAgent();
        $junk = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);
        $real = $this->feedListing(['agent' => $agent, 'price' => 18_000, 'category' => 2]);

        // Off: as it always was, a rent "under ₱30,000" includes the one typed as 25.
        $this->assertEqualsCanonicalizing([$junk, $real], $this->ids('?categories=For Rent&price_max=30000'));

        $this->switchOn(ListingFeedSwitch::GUARD, 'on');
        $this->assertSame([$real], $this->ids('?categories=For Rent&price_max=30000'));
        // No price bound, no change: the listing is still found by a plain search.
        $this->assertEqualsCanonicalizing([$junk, $real], $this->ids('?categories=For Rent'));
    }

    public function test_a_page_costs_the_same_number_of_queries_however_many_rows_it_has(): void
    {
        [$agent] = $this->feedAgent();
        $this->switchOn();
        $this->switchOn(ListingFeedSwitch::GUARD, 'on');

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->guest()->getJson('/api/listings')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (range(1, 2) as $i) {
            $this->feedListing(['agent' => $agent, 'price' => 25_000 + $i, 'category' => 2]);
        }
        $count(); // warm up: the switches and the category ids are read once, then remembered
        $few = $count();
        foreach (range(3, 12) as $i) {
            $this->feedListing(['agent' => $agent, 'price' => 25_000 + $i, 'category' => 2]);
        }
        $many = $count();

        $this->assertSame($few, $many, 'the number of queries must not grow with the rows on the page');
    }
}
