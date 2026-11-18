<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\FakesLrAgentStatus;
use Tests\TestCase;

/**
 * agents:sync-lr-status — asks Leuterio Realty about each agent and RECORDS the
 * answer in agents.lr_employment_*. It never changes agents.status (that is
 * agents:apply-lr-status, after a review), so the one thing that must hold
 * throughout is: nothing here can hide an agent.
 *
 * Every LR request is faked and `preventStrayRequests` is on; in-memory sqlite.
 */
class SyncLrAgentStatusTest extends TestCase
{
    use CreatesAuthTables, FakesLrAgentStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();

        config([
            'lr_status.sleep_ms' => 0,
            'lr_status.backoff_seconds' => 0,
            'lr_status.lock_store' => 'array',
        ]);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function role(string $name): int
    {
        return DB::table('roles')->where('name', $name)->value('id')
            ?? DB::table('roles')->insertGetId(['name' => $name]);
    }

    /** @param  array<string, mixed>  $agent */
    private function agent(string $email, array $agent = [], string $role = 'agent'): int
    {
        $userId = DB::table('users')->insertGetId([
            'name' => $email, 'email' => $email, 'password' => 'x',
            'role_id' => $this->role($role), 'last_online_at' => now(),
        ]);

        return DB::table('agents')->insertGetId($agent + [
            'user_id' => $userId, 'first_name' => 'Agent', 'last_name' => 'Test',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(int $agentId): object
    {
        return DB::table('agents')->where('id', $agentId)->first();
    }

    // ── recording ──────────────────────────────────────────────────────────

    public function test_it_records_what_lr_says_and_never_touches_the_agents_status(): void
    {
        $terminated = $this->agent('gone@example.test');
        $none = $this->agent('quiet@example.test');
        $unknown = $this->agent('stranger@example.test');
        $this->lr(['gone@example.test' => $this->lrHit('Terminated'), 'quiet@example.test' => $this->lrHit(null)]);

        $this->artisan('agents:sync-lr-status')
            ->expectsOutputToContain('2 found, 1 not found, 0 errors')
            ->assertSuccessful();

        $row = $this->row($terminated);
        $this->assertSame('found', $row->lr_employment_lookup);
        $this->assertSame('terminated', $row->lr_employment_status);
        $this->assertNotNull($row->lr_employment_checked_at);
        $this->assertNull($row->lr_employment_changed_at, 'the first observation leaves changed_at NULL');

        $this->assertSame('', $this->row($none)->lr_employment_status);

        $miss = $this->row($unknown);
        $this->assertSame('not_found', $miss->lr_employment_lookup);
        $this->assertNull($miss->lr_employment_status);

        foreach ([$terminated, $none, $unknown] as $id) {
            $this->assertSame('active', $this->row($id)->status, 'the sync must never change agents.status');
        }
    }

    public function test_a_change_from_a_recorded_value_moves_changed_at_and_the_same_value_does_not(): void
    {
        $stale = Carbon::now()->subDays(10);
        $changed = $this->agent('changed@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => $stale]);
        $same = $this->agent('same@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => $stale]);
        $fromNone = $this->agent('fromnone@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => '', 'lr_employment_checked_at' => $stale]);
        $this->lr([
            'changed@example.test' => $this->lrHit('inactive'),
            'same@example.test' => $this->lrHit('active'),
            'fromnone@example.test' => $this->lrHit('terminated'),
        ]);

        $this->artisan('agents:sync-lr-status')->assertSuccessful();

        $this->assertNotNull($this->row($changed)->lr_employment_changed_at);
        $this->assertSame('inactive', $this->row($changed)->lr_employment_status);
        $this->assertNull($this->row($same)->lr_employment_changed_at);
        // LR having no status and then setting one is a real change.
        $this->assertNotNull($this->row($fromNone)->lr_employment_changed_at);
    }

    public function test_an_error_is_recorded_for_a_retry_and_keeps_the_last_good_status(): void
    {
        $recorded = Carbon::now()->subDays(10);
        $id = $this->agent('flaky@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => 'inactive', 'lr_employment_checked_at' => $recorded]);
        $this->lr(['flaky@example.test' => Http::response('', 500)]);

        $this->artisan('agents:sync-lr-status')
            ->expectsOutputToContain('0 found, 0 not found, 1 errors')
            ->assertSuccessful();

        $row = $this->row($id);
        $this->assertSame('error', $row->lr_employment_lookup);
        $this->assertSame('inactive', $row->lr_employment_status, 'an outage must not erase what we knew');
        $this->assertTrue(Carbon::parse($row->lr_employment_checked_at)->gt($recorded), 'stamped, so it is retried tomorrow rather than starving the run');
        $this->assertSame('active', $row->status);
    }

    public function test_a_429_is_not_recorded_and_three_in_a_row_end_the_run(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->agent("a{$n}@example.test");
        }
        $this->lr(array_combine(
            array_map(fn ($n) => "a{$n}@example.test", range(1, 5)),
            array_fill(0, 5, Http::response('', 429, ['X-RateLimit-Remaining' => '0'])),
        ));

        $this->artisan('agents:sync-lr-status')
            ->expectsOutputToContain('rate-limiting')
            ->assertSuccessful();

        $this->assertCount(3, $this->asked());
        foreach ($ids as $id) {
            $this->assertNull($this->row($id)->lr_employment_lookup, 'a throttled lookup says nothing about the agent');
        }
    }

    // ── who is asked about ─────────────────────────────────────────────────

    public function test_only_agents_are_looked_up_not_staff_or_removed_agents(): void
    {
        $this->agent('agent@example.test');
        $this->agent('boss@example.test', [], 'admin');
        $this->agent('desk@example.test', [], 'secretary');
        $this->agent('editor@example.test', [], 'editor');
        $this->agent('removed@example.test', ['deleted_at' => now()]);
        $this->lr([]);

        $this->artisan('agents:sync-lr-status')->assertSuccessful();

        $this->assertSame(['agent@example.test'], $this->asked());
    }

    public function test_the_lookup_key_is_lr_email_when_set_else_the_login_email_trimmed_and_lowercased(): void
    {
        $this->agent('Login@Example.test', ['lr_email' => ' LR.Address@Example.test ']);
        $this->agent(' Plain@Example.TEST ');
        $this->lr([]);

        $this->artisan('agents:sync-lr-status')->assertSuccessful();

        $asked = $this->asked();
        sort($asked);
        $this->assertSame(['lr.address@example.test', 'plain@example.test'], $asked);
    }

    public function test_an_address_another_agent_holds_as_their_lr_email_is_not_looked_up(): void
    {
        // Looking it up would put LR's answer about agent A on agent B.
        $this->agent('a-login@example.test', ['lr_email' => 'shared@example.test']);
        $b = $this->agent('shared@example.test');
        $this->lr([]);

        $this->artisan('agents:sync-lr-status')->assertSuccessful();

        $this->assertSame(['shared@example.test'], $this->asked(), 'only A, via its lr_email');
        $this->assertNull($this->row($b)->lr_employment_lookup);
    }

    public function test_an_unusable_address_is_recorded_as_an_error_without_calling_lr(): void
    {
        $id = $this->agent('broken@');
        $this->lr([]);

        $this->artisan('agents:sync-lr-status')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('error', $this->row($id)->lr_employment_lookup);
    }

    // ── what is due, and in what order ─────────────────────────────────────

    public function test_only_agents_due_for_a_recheck_are_asked_about(): void
    {
        $at = fn (int $daysAgo) => Carbon::now()->subDays($daysAgo);
        $this->agent('never@example.test');
        $this->agent('found-fresh@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => $at(3)]);
        $this->agent('found-due@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => $at(8)]);
        $this->agent('missing-fresh@example.test', ['lr_employment_lookup' => 'not_found', 'lr_employment_checked_at' => $at(10)]);
        $this->agent('missing-due@example.test', ['lr_employment_lookup' => 'not_found', 'lr_employment_checked_at' => $at(31)]);
        $this->agent('error-fresh@example.test', ['lr_employment_lookup' => 'error', 'lr_employment_checked_at' => Carbon::now()->subHours(3)]);
        $this->agent('error-due@example.test', ['lr_employment_lookup' => 'error', 'lr_employment_checked_at' => $at(2)]);
        $this->agent('blocked-fresh@example.test', ['status' => 'resigned', 'lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => $at(10)]);
        $this->agent('blocked-due@example.test', ['status' => 'resigned', 'lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => $at(31)]);
        $this->lr([]);

        $this->artisan('agents:sync-lr-status')->assertSuccessful();

        $asked = $this->asked();
        sort($asked);
        $this->assertSame([
            'blocked-due@example.test',
            'error-due@example.test',
            'found-due@example.test',
            'missing-due@example.test',
            'never@example.test',
        ], $asked);
    }

    public function test_never_checked_agents_go_first_active_before_blocked_then_the_oldest(): void
    {
        $this->agent('older@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => Carbon::now()->subDays(20)]);
        $this->agent('oldest@example.test', ['lr_employment_lookup' => 'found', 'lr_employment_status' => 'active', 'lr_employment_checked_at' => Carbon::now()->subDays(40)]);
        $this->agent('blocked-never@example.test', ['status' => 'terminated']);
        $this->agent('active-never@example.test');
        $this->lr([]);

        $this->artisan('agents:sync-lr-status', ['--limit' => 4])->assertSuccessful();

        $this->assertSame([
            'active-never@example.test',
            'blocked-never@example.test',
            'oldest@example.test',
            'older@example.test',
        ], $this->asked());
    }

    public function test_limit_caps_the_lookups(): void
    {
        foreach (range(1, 5) as $n) {
            $this->agent("a{$n}@example.test");
        }
        $this->lr([]);

        $this->artisan('agents:sync-lr-status', ['--limit' => 2])->assertSuccessful();

        $this->assertCount(2, $this->asked());
    }

    // ── safety ─────────────────────────────────────────────────────────────

    public function test_a_dry_run_records_nothing(): void
    {
        $id = $this->agent('gone@example.test');
        $this->lr(['gone@example.test' => $this->lrHit('terminated')]);

        $this->artisan('agents:sync-lr-status', ['--dry-run' => true])
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->assertCount(1, $this->asked());
        $this->assertNull($this->row($id)->lr_employment_lookup);
        $this->assertNull($this->row($id)->lr_employment_checked_at);
    }

    public function test_a_second_run_while_one_is_in_progress_does_nothing(): void
    {
        $this->agent('gone@example.test');
        $this->lr([]);
        $held = Cache::store('array')->lock('lr-status-sync', 60);
        $this->assertTrue($held->get());

        $this->artisan('agents:sync-lr-status')
            ->expectsOutputToContain('already running')
            ->assertSuccessful();

        Http::assertNothingSent();
        $held->release();
    }

    public function test_a_low_rate_limit_budget_does_not_stop_the_run(): void
    {
        $this->agent('one@example.test');
        $this->agent('two@example.test');
        $this->lr([
            'one@example.test' => $this->lrHit('active', remaining: 3),
            'two@example.test' => $this->lrHit('active', remaining: 3),
        ]);

        $this->artisan('agents:sync-lr-status')
            ->expectsOutputToContain('budget low')
            ->expectsOutputToContain('2 found')
            ->assertSuccessful();
    }

    // ── the failure modes the review found ─────────────────────────────────

    public function test_an_oversized_status_from_lr_cannot_wedge_the_queue(): void
    {
        // The column is VARCHAR(32) and MySQL is strict: stored as-is, this would
        // throw on every run, and being first in line it would starve everyone
        // behind it.
        $odd = $this->agent('odd@example.test');
        $next = $this->agent('next@example.test');
        $this->lr([
            'odd@example.test' => $this->lrHit('Resigned - pending clearance 2026-12-01, see the HR memo of 14 Nov'),
            'next@example.test' => $this->lrHit('inactive'),
        ]);

        $this->artisan('agents:sync-lr-status')->expectsOutputToContain('2 found')->assertSuccessful();

        $this->assertLessThanOrEqual(32, mb_strlen($this->row($odd)->lr_employment_status));
        $this->assertSame('inactive', $this->row($next)->lr_employment_status, 'the agent behind it is still asked and recorded');
    }

    public function test_one_agent_that_cannot_be_recorded_does_not_stop_the_run(): void
    {
        $first = $this->agent('first@example.test');
        $broken = $this->agent('broken@example.test');
        $last = $this->agent('last@example.test');
        // The database refuses any write to one agent — as a bad value or a deadlock would.
        DB::unprepared("CREATE TRIGGER refuse_one BEFORE UPDATE ON agents WHEN NEW.id = {$broken} BEGIN SELECT RAISE(ABORT, 'boom'); END;");
        $this->lr([
            'first@example.test' => $this->lrHit('active'),
            'broken@example.test' => $this->lrHit('active'),
            'last@example.test' => $this->lrHit('active'),
        ]);

        $this->artisan('agents:sync-lr-status')
            ->expectsOutputToContain('2 found, 0 not found, 1 errors')
            ->assertSuccessful();

        $this->assertSame('found', $this->row($first)->lr_employment_lookup);
        $this->assertNull($this->row($broken)->lr_employment_lookup);
        $this->assertSame('found', $this->row($last)->lr_employment_lookup, 'the agent after the failure is still processed');
    }

    public function test_the_run_stops_when_its_time_budget_is_used_up(): void
    {
        // A slow LR (30 s per call) could carry 300 lookups past the lock and into
        // the next hour's run — two syncs at once on a shared budget.
        config(['lr_status.max_run_minutes' => 1]);
        $a = $this->agent('a@example.test');
        $b = $this->agent('b@example.test');
        $c = $this->agent('c@example.test');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->travel(2)->minutes(); // the first lookup takes longer than the whole budget

            return $this->lrReply($this->askedAbout($request), 'active');
        });

        $this->artisan('agents:sync-lr-status')
            ->expectsOutputToContain('time budget')
            ->assertSuccessful();

        $this->assertCount(1, $this->asked());
        $this->assertSame('found', $this->row($a)->lr_employment_lookup);
        $this->assertNull($this->row($b)->lr_employment_lookup);
        $this->assertNull($this->row($c)->lr_employment_lookup);
    }

    public function test_options_that_are_not_whole_numbers_are_refused_not_quietly_zero(): void
    {
        $this->agent('a@example.test');
        Http::preventStrayRequests();
        Http::fake();

        foreach ([['--limit' => 'abc'], ['--limit' => '0'], ['--limit' => '-3'], ['--sleep-ms' => '1s'], ['--sleep-ms' => 'fast']] as $bad) {
            $this->artisan('agents:sync-lr-status', $bad)->assertFailed();
        }

        Http::assertNothingSent();
    }
}
