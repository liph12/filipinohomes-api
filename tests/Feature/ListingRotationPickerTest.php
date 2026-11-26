<?php

namespace Tests\Feature;

use App\Services\Agent\AgentCachePurger;
use App\Services\Jobs\ListingService;
use App\Services\Listing\ListingFeedSwitch;
use App\Services\Listing\ListingRotationPicker as Picker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * The hourly rotation's "fair" picker: which listings it stamps, how often each kind gets a turn, and that it
 * writes nothing but `updated_at` (and its own queue position).
 */
class ListingRotationPickerTest extends TestCase
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

    /** @return array<int, array<string, mixed>> the queue, keyed by listing id */
    private function queueById(): array
    {
        return collect(Picker::queue())->keyBy('id')->all();
    }

    public function test_the_pool_is_only_listings_a_buyer_can_act_on(): void
    {
        [$active] = $this->feedAgent();
        [$resigned] = $this->feedAgent('resigned');
        $ok = $this->completeListing(['agent' => $active]);
        $sold = $this->completeListing(['agent' => $active, 'status' => 'sold']);
        $private = $this->completeListing(['agent' => $active, 'visibility' => 'private']);
        $offAgent = $this->completeListing(['agent' => $resigned]);
        $junkPrice = $this->completeListing(['agent' => $active, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);
        $deleted = $this->completeListing(['agent' => $active]);
        DB::table('listings')->where('id', $deleted)->update(['deleted_at' => now()]);

        $this->assertSame([$ok], array_keys($this->queueById()));
        $this->assertNotContains($sold, array_keys($this->queueById()));
        $this->assertNotContains($private, array_keys($this->queueById()));
        $this->assertNotContains($offAgent, array_keys($this->queueById()));
        $this->assertNotContains($junkPrice, array_keys($this->queueById()));
    }

    public function test_complete_means_photos_description_pin_and_an_area(): void
    {
        [$agent] = $this->feedAgent();
        $complete = $this->completeListing(['agent' => $agent]);
        $fourPhotos = $this->completeListing(['agent' => $agent, 'photos' => 4]);
        $shortText = $this->completeListing(['agent' => $agent, 'description' => 119]);
        $noPin = $this->completeListing(['agent' => $agent, 'pin' => false]);
        $smallArea = $this->completeListing(['agent' => $agent, 'floor' => 9]);
        // A lot is measured by its lot area; a house only by its floor area.
        $lot = $this->completeListing(['agent' => $agent, 'type' => 'Land', 'floor' => null, 'lot' => 300]);
        $houseLotOnly = $this->completeListing(['agent' => $agent, 'type' => 'House', 'floor' => null, 'lot' => 300]);

        $q = $this->queueById();
        $this->assertSame('complete', $q[$complete]['class']);
        $this->assertSame('complete', $q[$lot]['class']);
        foreach ([$fourPhotos, $shortText, $noPin, $smallArea, $houseLotOnly] as $id) {
            $this->assertSame('incomplete', $q[$id]['class'], "listing {$id}");
        }
    }

    public function test_a_listing_is_held_back_for_a_copy_a_sold_claim_or_an_agent_who_is_gone(): void
    {
        [$agent] = $this->feedAgent();
        $original = $this->completeListing(['agent' => $agent, 'name' => 'Studio Unit at Solinea!', 'price' => 1_000_000]);
        $copy = $this->completeListing(['agent' => $agent, 'name' => 'studio unit at solinea', 'price' => 1_015_000]);
        $otherPrice = $this->completeListing(['agent' => $agent, 'name' => 'Studio Unit at Solinea', 'price' => 1_100_000]);

        [$other] = $this->feedAgent();
        $claimed = $this->completeListing(['agent' => $other]);
        $weakClaim = $this->completeListing(['agent' => $other]);
        $oldClaim = $this->completeListing(['agent' => $other]);
        DB::table('listing_sold_claims')->insert([
            ['listing_id' => $claimed, 'tier' => 'strong', 'said_at' => now()->subDays(5)],
            ['listing_id' => $weakClaim, 'tier' => 'weak', 'said_at' => now()->subDays(5)],
            ['listing_id' => $oldClaim, 'tier' => 'strong', 'said_at' => now()->subDays(45)],
        ]);

        [$idle] = $this->feedAgent('active', now()->subDays(60)->toDateTimeString());
        [$recent] = $this->feedAgent('active', now()->subDays(10)->toDateTimeString());
        [$never] = $this->feedAgent('active', null);
        [$unanswered] = $this->feedAgent();
        DB::table('agents')->where('id', $unanswered)->update(['response_sample_size' => 6, 'unanswered_response_pct' => 60]);
        [$fewInquiries] = $this->feedAgent();
        DB::table('agents')->where('id', $fewInquiries)->update(['response_sample_size' => 4, 'unanswered_response_pct' => 100]);
        $ofIdle = $this->completeListing(['agent' => $idle]);
        $ofRecent = $this->completeListing(['agent' => $recent]);
        $ofNever = $this->completeListing(['agent' => $never]);
        $ofUnanswered = $this->completeListing(['agent' => $unanswered]);
        $ofFew = $this->completeListing(['agent' => $fewInquiries]);

        $q = $this->queueById();
        $this->assertSame('complete', $q[$original]['class']);      // the oldest is the original
        $this->assertSame(['copy'], $q[$copy]['reasons']);
        $this->assertSame('complete', $q[$otherPrice]['class']);    // a different price is a different listing
        $this->assertSame(['sold_claim'], $q[$claimed]['reasons']);
        $this->assertSame('complete', $q[$weakClaim]['class']);     // only the strong tier counts...
        $this->assertSame('complete', $q[$oldClaim]['class']);      // ...and only within 30 days
        $this->assertSame(['agent_idle'], $q[$ofIdle]['reasons']);
        $this->assertSame('complete', $q[$ofRecent]['class']);
        // "Never signed in" is not "idle": most agents work through the company's own systems.
        $this->assertSame('complete', $q[$ofNever]['class']);
        $this->assertSame(['agent_unanswered'], $q[$ofUnanswered]['reasons']);
        $this->assertSame('complete', $q[$ofFew]['class']);         // four inquiries are too few to judge
    }

    public function test_each_kind_gets_its_turn_as_often_as_its_class_says(): void
    {
        // Eight listings of each class, in one trip round the circle.
        $queue = [];
        foreach (['complete', 'incomplete', 'heldback'] as $classIndex => $class) {
            for ($i = 1; $i <= 8; $i++) {
                $queue[] = ['id' => $classIndex * 100 + $i, 'pos' => $classIndex * 1000 + $i, 'class' => $class];
            }
        }

        $turns = []; // listing id => the laps it was picked in
        for ($lap = 0; $lap < 4; $lap++) {
            $picked = Picker::pick($queue, 0, $lap, 1000)['picked'];
            $perClass = array_count_values(array_column($picked, 'class'));
            // Per lap: every complete listing, half the incomplete ones, a quarter of the held-back ones.
            $this->assertSame(8, $perClass['complete']);
            $this->assertSame(4, $perClass['incomplete']);
            $this->assertSame(2, $perClass['heldback']);
            foreach ($picked as $e) {
                $turns[$e['id']][] = $lap;
            }
        }

        // Over four laps: complete every lap, incomplete every 2nd, held back once, and nobody is starved
        // (the +id in the rule staggers which listings are due, so they do not all fall on the same lap).
        foreach ($queue as $e) {
            $expected = ['complete' => 4, 'incomplete' => 2, 'heldback' => 1][$e['class']];
            $this->assertCount($expected, $turns[$e['id']], "listing {$e['id']} ({$e['class']})");
        }
    }

    public function test_one_agents_listings_are_spread_around_the_circle(): void
    {
        $n = 20;
        $seats = array_map(fn ($k) => Picker::seat(7, $k) / 1_000_000_000, range(0, $n - 1));
        sort($seats);
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = $seats[$i] - $seats[$i - 1];
        }
        $gaps[] = 1 - $seats[$n - 1] + $seats[0]; // round the circle

        // The golden ratio keeps every gap near 1/n: none is bunched, none is a hole.
        $this->assertGreaterThan(0.3 / $n, min($gaps));
        $this->assertLessThan(2.5 / $n, max($gaps));
        // Different agents start at different places.
        $this->assertNotSame(Picker::seat(7, 0), Picker::seat(8, 0));
    }

    public function test_running_stamps_a_batch_a_second_apart_in_queue_order_and_moves_the_cursor(): void
    {
        ListingFeedSwitch::set('listings.rotation.batch', '3');
        $before = now()->subYear()->toDateTimeString();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            [$agent] = $this->feedAgent();
            $ids[] = $this->completeListing(['agent' => $agent, 'updated' => $before]);
        }
        Queue::fake();

        $queue = Picker::queue();
        $result = Picker::run();

        $this->assertSame(3, $result['processed']);
        $firstThree = array_slice(array_column($queue, 'id'), 0, 3);
        $stamped = DB::table('listings')->whereIn('id', $firstThree)->pluck('updated_at', 'id')->all();
        // The first in the queue is the newest: the batch appears in queue order.
        $times = array_map(fn ($id) => strtotime($stamped[$id]), $firstThree);
        $this->assertTrue($times[0] > $times[1] && $times[1] > $times[2]);
        $this->assertEqualsWithDelta(time(), $times[0], 5);
        // The other two were not touched.
        foreach (array_slice(array_column($queue, 'id'), 3) as $id) {
            $this->assertSame($before, DB::table('listings')->where('id', $id)->value('updated_at'));
        }

        // Only `updated_at` was written: no audit rows, no queued jobs.
        $this->assertSame(0, DB::table('audits')->count());
        Queue::assertNothingPushed();

        // The queue position and lap are remembered, in the existing scheduler_states table.
        [$cursor, $lap] = Picker::state();
        $this->assertGreaterThan(0, $cursor);
        $this->assertSame(0, $lap);

        // The next call continues where this one stopped, and wraps into lap 1 (a complete listing is due every lap).
        $second = Picker::run();
        $this->assertSame(3, $second['processed']);
        [, $lapAfter] = Picker::state();
        $this->assertSame(1, $lapAfter);
    }

    public function test_the_preview_changes_nothing(): void
    {
        [$agent] = $this->feedAgent();
        $id = $this->completeListing(['agent' => $agent, 'updated' => now()->subYear()->toDateTimeString()]);
        $stamp = DB::table('listings')->where('id', $id)->value('updated_at');

        $preview = Picker::preview(10);

        $this->assertSame(1, $preview['pool']);
        $this->assertSame($id, $preview['next'][0]['id']);
        $this->assertSame($stamp, DB::table('listings')->where('id', $id)->value('updated_at'));
        $this->assertSame(0, DB::table('scheduler_states')->count());
    }

    public function test_the_built_in_walk_is_what_runs_unless_the_switch_says_fair(): void
    {
        [$agent] = $this->feedAgent();
        $a = $this->completeListing(['agent' => $agent, 'updated' => now()->subYear()->toDateTimeString()]);
        $b = $this->feedListing(['agent' => $agent, 'status' => 'sold', 'updated' => now()->subYear()->toDateTimeString()]);

        // Default ('id'): the next ids after the cursor, sold ones included, exactly as it was built.
        $result = app(ListingService::class)->updateBatchListings();
        $this->assertSame(2, $result['processed']);
        $this->assertSame($b, $result['last_id']);
        $this->assertEqualsWithDelta(time(), strtotime(DB::table('listings')->where('id', $b)->value('updated_at')), 5);
        $this->assertSame($b, (int) DB::table('scheduler_states')->where('key', 'listing_update')->value('last_id'));

        // 'fair': only the listing a buyer can act on is stamped, and the built-in cursor is left alone.
        DB::table('listings')->update(['updated_at' => now()->subYear()->toDateTimeString()]);
        DB::table('scheduler_states')->where('key', 'listing_update')->update(['last_id' => 0]);
        ListingFeedSwitch::set(ListingFeedSwitch::PICKER, 'fair');
        $result = app(ListingService::class)->updateBatchListings();
        $this->assertSame('fair', $result['picker']);
        $this->assertSame(1, $result['processed']);
        $this->assertEqualsWithDelta(time(), strtotime(DB::table('listings')->where('id', $a)->value('updated_at')), 5);
        $this->assertLessThan(time() - 86_400, strtotime(DB::table('listings')->where('id', $b)->value('updated_at')));
        $this->assertSame(0, (int) DB::table('scheduler_states')->where('key', 'listing_update')->value('last_id'));
    }
}
