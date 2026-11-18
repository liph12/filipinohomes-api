<?php

namespace Tests\Feature;

use App\Services\LeuterioreRealty\LrEmploymentRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * LrEmploymentRecorder::record — how an LR answer is written down, and above
 * all when `lr_employment_changed_at` moves. That timestamp is what lets an
 * admin's decision stick (agents:apply-lr-status holds an agent an admin
 * touched AFTER LR last changed its mind), so it must only ever advance when
 * the value in the row really changes.
 *
 * In-memory sqlite; no LR involved.
 */
class LrEmploymentRecorderTest extends TestCase
{
    use CreatesAuthTables;

    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        $userId = DB::table('users')->insertGetId([
            'name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'role_id' => 1,
        ]);
        $this->agentId = DB::table('agents')->insertGetId([
            'user_id' => $userId, 'first_name' => 'A', 'last_name' => 'B', 'status' => 'active',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        Carbon::setTestNow('2026-11-18 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(): object
    {
        return DB::table('agents')->where('id', $this->agentId)->first();
    }

    private function found(string $status): array
    {
        return ['result' => 'found', 'status' => $status];
    }

    private function record(array $lookup): void
    {
        (new LrEmploymentRecorder())->record($this->agentId, $lookup);
    }

    private function at(string $when): void
    {
        Carbon::setTestNow($when);
    }

    public function test_the_first_observation_records_the_value_and_leaves_changed_at_null(): void
    {
        $this->record($this->found('inactive'));

        $row = $this->row();
        $this->assertSame('found', $row->lr_employment_lookup);
        $this->assertSame('inactive', $row->lr_employment_status);
        $this->assertSame('2026-11-18 10:00:00', $row->lr_employment_checked_at);
        $this->assertNull($row->lr_employment_changed_at, 'an admin decision made before the sync existed must still count');
    }

    public function test_a_different_value_moves_changed_at(): void
    {
        $this->record($this->found('active'));
        $this->at('2026-11-20 08:00:00');

        $this->record($this->found('terminated'));

        $this->assertSame('terminated', $this->row()->lr_employment_status);
        $this->assertSame('2026-11-20 08:00:00', $this->row()->lr_employment_changed_at);
    }

    public function test_the_same_value_again_never_moves_changed_at_whoever_asks(): void
    {
        // The sync records X's new value on Monday; on Wednesday the apply
        // command, working from a snapshot it read before Monday, records the
        // SAME value. Deciding "did it change?" from that stale snapshot would
        // re-advance changed_at past an admin override made on Tuesday and
        // un-hold the agent. The comparison is made against the row itself.
        $this->record($this->found('active'));
        $this->at('2026-11-23 09:00:00');
        $this->record($this->found('inactive'));
        $this->at('2026-11-25 09:00:00');

        $this->record($this->found('inactive'));
        $this->record($this->found('inactive'));

        $this->assertSame('2026-11-23 09:00:00', $this->row()->lr_employment_changed_at);
        $this->assertSame('2026-11-25 09:00:00', $this->row()->lr_employment_checked_at, 'checked_at still advances');
    }

    public function test_lr_having_no_status_and_then_setting_one_is_a_change_and_so_is_the_way_back(): void
    {
        $this->record($this->found(''));
        $this->assertNull($this->row()->lr_employment_changed_at);

        $this->at('2026-11-19 10:00:00');
        $this->record($this->found('inactive'));
        $this->assertSame('2026-11-19 10:00:00', $this->row()->lr_employment_changed_at);

        $this->at('2026-11-20 10:00:00');
        $this->record($this->found(''));
        $this->assertSame('2026-11-20 10:00:00', $this->row()->lr_employment_changed_at);
    }

    public function test_a_miss_or_an_error_keeps_what_we_knew_and_only_restamps_the_lookup(): void
    {
        $this->record($this->found('inactive'));
        $this->at('2026-11-21 10:00:00');

        $this->record(['result' => 'not_found', 'status' => null]);
        $row = $this->row();
        $this->assertSame('not_found', $row->lr_employment_lookup);
        $this->assertSame('inactive', $row->lr_employment_status, 'an outage or a miss must not erase the last good value');
        $this->assertNull($row->lr_employment_changed_at);
        $this->assertSame('2026-11-21 10:00:00', $row->lr_employment_checked_at);

        $this->record(['result' => 'error', 'status' => null]);
        $this->assertSame('error', $this->row()->lr_employment_lookup);
        $this->assertSame('inactive', $this->row()->lr_employment_status);
    }

    public function test_recording_touches_no_audit_row_and_not_updated_at(): void
    {
        $this->record($this->found('active'));
        $this->record($this->found('terminated'));

        $this->assertSame('2026-01-01 00:00:00', $this->row()->updated_at, 'agent pages and sitemaps read updated_at');
        $this->assertSame(0, DB::table('audits')->count());
        $this->assertSame('active', $this->row()->status, 'and agents.status is never touched here');
    }
}
