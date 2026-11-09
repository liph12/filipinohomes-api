<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * The agent who showed "Active" in the admin table and "Account Deactivated"
 * at her own login, with Total Logins climbing the whole time.
 *
 * The 45-day dormancy rule used to be evaluated at sign-in, before anything
 * stamped presence. On an account whose last_online_at was still NULL the
 * baseline fell through to created_at, so for a long-standing agent the
 * window had already closed and the sign-in itself flipped her back to
 * deactivated. Every admin reactivation was undone by her very next login and
 * nothing surfaced why — the admin could not win at any point.
 *
 * Signing in now records activity (User::recordLoginActivity) and the rule
 * moved to the agents:deactivate-dormant sweep, so it lands on the agents who
 * are absent rather than the ones coming back. These tests hold all three
 * pieces in place: the login path, the sweep, and the repair that lets the
 * already-locked-out back in.
 *
 * Today is frozen at 2026-11-09, so the grace window opens 2026-09-25.
 */
class AgentDormancyLockoutTest extends TestCase
{
    use CreatesAuthTables;

    private const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 2, 'name' => 'agent']]);

        $this->travelTo(Carbon::parse('2026-11-09 15:00:00'));
    }

    private function agentUser(string $createdAt, ?string $lastOnlineAt = null, string $status = 'active'): User
    {
        $user = User::forceCreate([
            'name'           => 'Test Agent',
            'email'          => uniqid('agent', true) . '@example.com',
            'password'       => self::PASSWORD,
            'role_id'        => 2,
            'created_at'     => $createdAt,
            'last_online_at' => $lastOnlineAt,
        ]);

        Agent::forceCreate([
            'user_id'    => $user->id,
            'first_name' => 'Test',
            'last_name'  => 'Agent',
            'status'     => $status,
        ]);

        return $user;
    }

    private function statusOf(User $user): string
    {
        return Agent::where('user_id', $user->id)->value('status');
    }

    private function login(User $user): void
    {
        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
    }

    /** An audit row of the shape the repair command reads. */
    private function recordStatusAudit(User $user, string $source): void
    {
        DB::table('audits')->insert([
            'event'          => 'updated',
            'auditable_type' => Agent::class,
            'auditable_id'   => Agent::where('user_id', $user->id)->value('id'),
            'old_values'     => json_encode(['status' => 'active']),
            'new_values'     => json_encode(['status' => 'deactivated']),
            'source'         => $source,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    // ── The reported bug ───────────────────────────────────────────────────

    public function test_an_admin_reactivation_survives_the_agents_next_login(): void
    {
        // The reported account: a long-standing agent, no heartbeat on record,
        // just set back to Active by an admin.
        $user = $this->agentUser('2025-03-01 00:00:00', null, 'active');

        $this->login($user);

        $this->assertSame(
            'active',
            $this->statusOf($user),
            'Signing in must not undo the admin\'s reactivation — that is the reported bug.',
        );
    }

    public function test_signing_in_stamps_presence(): void
    {
        $user = $this->agentUser('2025-03-01 00:00:00');

        $this->login($user);

        $this->assertSame(now()->toDateTimeString(), $user->fresh()->last_online_at?->toDateTimeString());
    }

    // ── The sweep ──────────────────────────────────────────────────────────

    public function test_the_sweep_reports_without_writing_by_default(): void
    {
        $user = $this->agentUser('2025-03-01 00:00:00');

        $this->artisan('agents:deactivate-dormant')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame('active', $this->statusOf($user));
    }

    public function test_the_sweep_deactivates_a_dormant_agent_with_force(): void
    {
        $user = $this->agentUser('2025-03-01 00:00:00');

        $this->artisan('agents:deactivate-dormant --force')->assertSuccessful();

        $this->assertSame('deactivated', $this->statusOf($user));
    }

    public function test_the_sweep_leaves_a_recently_active_agent_alone(): void
    {
        $user = $this->agentUser('2025-03-01 00:00:00', '2026-11-08 09:00:00');

        $this->artisan('agents:deactivate-dormant --force')->assertSuccessful();

        $this->assertSame(
            'active',
            $this->statusOf($user),
            'Control failed: the sweep deactivated an agent inside the window, so the tests above prove nothing.',
        );
    }

    public function test_the_sweep_limit_caps_one_run(): void
    {
        $first = $this->agentUser('2025-03-01 00:00:00');
        $second = $this->agentUser('2025-03-02 00:00:00');

        $this->artisan('agents:deactivate-dormant --force --limit=1')->assertSuccessful();

        $statuses = [$this->statusOf($first), $this->statusOf($second)];
        sort($statuses);

        $this->assertSame(['active', 'deactivated'], $statuses);
    }

    // ── The repair ─────────────────────────────────────────────────────────

    public function test_the_repair_restores_an_agent_the_old_check_locked_out(): void
    {
        // Deactivated, yet signed in today — what being turned away at your
        // own door looks like in the data.
        $user = $this->agentUser('2025-03-01 00:00:00', '2026-11-09 15:35:00', 'deactivated');
        $this->recordStatusAudit($user, 'auto_dormant');

        $this->artisan('agents:repair-dormant-lockout --force')->assertSuccessful();

        $this->assertSame('active', $this->statusOf($user));
    }

    public function test_the_repair_reports_without_writing_by_default(): void
    {
        $user = $this->agentUser('2025-03-01 00:00:00', '2026-11-09 15:35:00', 'deactivated');
        $this->recordStatusAudit($user, 'auto_dormant');

        $this->artisan('agents:repair-dormant-lockout')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame('deactivated', $this->statusOf($user));
    }

    public function test_the_repair_leaves_an_admin_deactivation_alone(): void
    {
        $user = $this->agentUser('2025-03-01 00:00:00', '2026-11-09 15:35:00', 'deactivated');
        $this->recordStatusAudit($user, 'auto_dormant');
        // An admin set the status after the automatic one — their call stands.
        $this->recordStatusAudit($user, 'admin_status_dropdown');

        $this->artisan('agents:repair-dormant-lockout --force')->assertSuccessful();

        $this->assertSame('deactivated', $this->statusOf($user));
    }

    public function test_the_repair_leaves_a_genuinely_absent_agent_deactivated(): void
    {
        // Auto-deactivated and never came back — the rule was right about them.
        $user = $this->agentUser('2025-03-01 00:00:00', null, 'deactivated');
        $this->recordStatusAudit($user, 'auto_dormant');

        $this->artisan('agents:repair-dormant-lockout --force')->assertSuccessful();

        $this->assertSame('deactivated', $this->statusOf($user));
    }
}
