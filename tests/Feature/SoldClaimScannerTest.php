<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Reports\Executive\SoldClaims\SoldClaimMatcher;
use App\Services\Reports\Executive\SoldClaims\SoldClaimScanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The hourly scan behind "Said it's sold, still listed". Today is frozen at
 * Nov 10, 2026, 2 PM Manila; replies newer than 1:45 PM haven't settled.
 */
class SoldClaimScannerTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private Carbon $now;

    /** @var array<string, int> message ids by name */
    private array $msg = [];

    private int $saleListing;

    private int $rentListing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-11-10 14:00', 'Asia/Manila');
        Carbon::setTestNow($this->now);
        $this->createExecutiveReportTables();

        [$agentId, $agentUser] = $this->makeAgent();
        $admin = $this->makeUser(self::ADMIN);
        $alice = $this->makeUser(self::CLIENT);
        $bert = $this->makeUser(self::CLIENT);

        $this->saleListing = $this->makeListing($agentId, ['category' => 1]);
        $this->rentListing = $this->makeListing($agentId, ['category' => 2]);

        $this->makeInquiry($this->saleListing, $alice, '2026-11-08 09:00', ['agent_user_id' => $agentUser], [
            [$alice, '2026-11-08 09:00', 'Available pa po ba ito?'],
            [$agentUser, '2026-11-08 09:30', 'Sorry po, sold na po yung unit.'],
            [$agentUser, '2026-11-08 09:31', 'Pero may iba pa po akong listings.'],
            [$alice, '2026-11-08 09:40', 'Sold na po? Sayang.'],
        ]);
        $this->makeInquiry($this->rentListing, $bert, '2026-11-09 10:00', ['agent_user_id' => $agentUser], [
            [$agentUser, '2026-11-09 10:05', 'Rented na po yung unit'],
            [$agentUser, '2026-11-09 10:06', 'Sold na po ito', 'unsent'],
        ]);
        $this->makeInquiry($this->saleListing, $bert, '2026-11-09 11:00', ['agent_user_id' => $agentUser], [
            [$agentUser, '2026-11-09 11:05', 'This unit is already rented, good for investment'],
            [$admin, '2026-11-09 11:10', 'Nahalin na ni sir'],
            // Not settled yet at 2 PM — read on a later run.
            [$agentUser, '2026-11-10 13:50', 'Taken na po'],
        ]);

        foreach (DB::table('messages')->orderBy('id')->get(['id', 'body', 'status']) as $m) {
            $this->msg[$m->body.($m->status === 'unsent' ? ' (unsent)' : '')] = (int) $m->id;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scanner(): SoldClaimScanner
    {
        return new SoldClaimScanner(new SoldClaimMatcher);
    }

    /** @return array<int, array{tier: string, phrase: string, listing_id: int, speaker_role: ?string}> keyed by message id */
    private function claims(): array
    {
        return DB::table('listing_sold_claims')->orderBy('message_id')->get()->mapWithKeys(fn ($c) => [(int) $c->message_id => [
            'tier' => $c->tier, 'phrase' => $c->phrase, 'listing_id' => (int) $c->listing_id, 'speaker_role' => $c->speaker_role,
        ]])->all();
    }

    public function test_it_stores_agent_side_claims_and_nothing_else(): void
    {
        $result = $this->scanner()->run(now: $this->now);

        $this->assertSame([
            $this->msg['Sorry po, sold na po yung unit.'] => ['tier' => 'strong', 'phrase' => 'sold na', 'listing_id' => $this->saleListing, 'speaker_role' => 'agent'],
            $this->msg['Rented na po yung unit'] => ['tier' => 'strong', 'phrase' => 'rented na', 'listing_id' => $this->rentListing, 'speaker_role' => 'agent'],
            // A rent phrase on a sale listing is kept, but only as weak.
            $this->msg['This unit is already rented, good for investment'] => ['tier' => 'weak', 'phrase' => 'already rented', 'listing_id' => $this->saleListing, 'speaker_role' => 'agent'],
            // An admin answering for the agent is the agent side too.
            $this->msg['Nahalin na ni sir'] => ['tier' => 'strong', 'phrase' => 'na-halin', 'listing_id' => $this->saleListing, 'speaker_role' => 'admin'],
        ], $this->claims(), 'The client\'s "Sold na po?", the unsent reply and the unsettled one are all skipped.');

        $this->assertSame(['strong' => 3, 'medium' => 0, 'weak' => 1], $result['found']);
        $this->assertTrue($result['done']);
        $this->assertSame($this->msg['Taken na po'] - 1, $result['to'], 'The watermark stops before the first unsettled reply.');
        $this->assertSame(['version' => SoldClaimMatcher::VERSION, 'last_id' => $this->msg['Taken na po'] - 1], $this->scanner()->state());

        $stored = DB::table('listing_sold_claims')->where('message_id', $this->msg['Sorry po, sold na po yung unit.'])->first();
        $this->assertSame($this->utc('2026-11-08 09:30'), $stored->said_at);
        $this->assertSame('Sorry po, sold na po yung unit.', $stored->snippet);
    }

    public function test_a_later_run_reads_only_what_is_new_and_rereading_changes_nothing(): void
    {
        $this->scanner()->run(now: $this->now);
        $rowIds = DB::table('listing_sold_claims')->orderBy('id')->pluck('id', 'message_id')->all();

        $later = $this->now->copy()->addMinutes(20);
        $result = $this->scanner()->run(now: $later);
        $this->assertSame(1, $result['scanned'], 'Only the reply that has settled since.');
        $this->assertSame('taken na', $this->claims()[$this->msg['Taken na po']]['phrase']);

        // Reading everything again (as a second host running the same
        // scheduler would) converges on the very same rows.
        $this->scanner()->run(restart: true, now: $later);
        $again = DB::table('listing_sold_claims')->orderBy('id')->pluck('id', 'message_id')->all();
        $this->assertSame($rowIds, array_intersect_key($again, $rowIds));
        $this->assertCount(5, $again);
    }

    public function test_edited_unsent_and_deleted_replies_withdraw_their_claims(): void
    {
        $this->scanner()->run(now: $this->now);
        $edit = $this->now->copy()->addMinute()->utc()->format('Y-m-d H:i:s');

        DB::table('messages')->where('id', $this->msg['Sorry po, sold na po yung unit.'])
            ->update(['body' => 'Sorry po, available pa po yung unit.', 'status' => 'updated', 'updated_at' => $edit]);
        DB::table('messages')->where('id', $this->msg['Rented na po yung unit'])
            ->update(['status' => 'unsent', 'body' => null, 'updated_at' => $edit]);
        DB::table('messages')->where('id', $this->msg['Nahalin na ni sir'])->delete();
        // Edited but still a claim: kept, re-read.
        DB::table('messages')->where('id', $this->msg['This unit is already rented, good for investment'])
            ->update(['body' => 'Sorry, this unit is already sold', 'status' => 'updated', 'updated_at' => $edit]);

        $result = $this->scanner()->run(now: $this->now->copy()->addMinutes(2));

        $this->assertSame(4, $result['rechecked']);
        $this->assertSame(3, $result['dropped']);
        $this->assertSame([
            $this->msg['This unit is already rented, good for investment'] => ['tier' => 'strong', 'phrase' => 'already sold', 'listing_id' => $this->saleListing, 'speaker_role' => 'agent'],
        ], $this->claims());
    }

    public function test_a_new_matcher_version_reads_everything_again(): void
    {
        $this->scanner()->run(now: $this->now);
        // A row an older matcher stored for a reply the current one rejects.
        DB::table('listing_sold_claims')->insert([
            'message_id' => $this->msg['Pero may iba pa po akong listings.'], 'conversation_id' => 1, 'chat_id' => 1,
            'listing_id' => $this->saleListing, 'speaker_user_id' => 1, 'tier' => 'weak', 'phrase' => 'old', 'snippet' => 'old',
            'said_at' => $this->utc('2026-11-08 09:31'), 'matcher_version' => SoldClaimMatcher::VERSION - 1,
            'checked_at' => $this->utc('2026-11-10 14:00'),
        ]);
        // Any other version (0 is reserved for "never ran").
        Setting::set(SoldClaimScanner::SETTING, json_encode(['version' => SoldClaimMatcher::VERSION + 98, 'last_id' => 999]));

        $result = $this->scanner()->run(now: $this->now);

        $this->assertTrue($result['version_reset']);
        $this->assertSame(0, $result['from']);
        $this->assertSame(1, $result['removed']);
        $this->assertArrayNotHasKey($this->msg['Pero may iba pa po akong listings.'], $this->claims());
        $this->assertCount(4, $this->claims());
    }

    public function test_the_cap_and_the_dry_run(): void
    {
        $dry = $this->scanner()->run(dryRun: true, now: $this->now);
        $this->assertSame(['strong' => 3, 'medium' => 0, 'weak' => 1], $dry['found']);
        $this->assertCount(4, $dry['samples']);
        $this->assertSame(0, DB::table('listing_sold_claims')->count());
        $this->assertSame(0, $this->scanner()->state()['last_id']);

        $capped = $this->scanner()->run(max: 2, now: $this->now);
        $this->assertSame(2, $capped['scanned']);
        $this->assertFalse($capped['done']);
        $this->assertSame($this->msg['Pero may iba pa po akong listings.'], $capped['to'], 'Two agent replies read: the sold claim and the one after it.');
        $this->assertSame([$this->msg['Sorry po, sold na po yung unit.']], array_keys($this->claims()));

        $rest = $this->scanner()->run(now: $this->now);
        $this->assertTrue($rest['done']);
        $this->assertCount(4, $this->claims());
    }

    public function test_the_command(): void
    {
        $this->artisan('reports:scan-sold-claims --dry-run')
            ->expectsOutputToContain('3 strong, 0 medium, 1 weak')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        $this->assertSame(0, DB::table('listing_sold_claims')->count());

        $this->artisan('reports:scan-sold-claims')
            ->expectsOutputToContain('Caught up.')
            ->expectsOutputToContain('Stored in all: 3 strong, 0 medium, 1 weak claims.')
            ->assertSuccessful();
        $this->assertSame(4, DB::table('listing_sold_claims')->count());

        // A second run reads nothing new — and still says what is stored.
        $this->artisan('reports:scan-sold-claims')
            ->expectsOutputToContain('Read 0 agent replies')
            ->expectsOutputToContain('Stored in all: 3 strong, 0 medium, 1 weak claims.')
            ->assertSuccessful();
    }
}
