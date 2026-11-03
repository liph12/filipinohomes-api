<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * Dormancy (45 days without a heartbeat → "deactivated") measures a
 * never-online account from its signup date. It used to fall back to the
 * 2026-07-02 policy start alone, so from 2026-08-16 on every brand-new agent
 * was deactivated at its very first login.
 *
 * The login-time check (User::deactivateAgentIfDormant) and the admin guard's
 * scope (User::scopeDormantSince) are one definition; the cases below hold
 * both to it. Today is frozen at 2026-10-31, so the grace window opens
 * 2026-09-16 00:00.
 */
class AgentDormancyBaselineTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 2, 'name' => 'agent'],
            ['id' => 3, 'name' => 'client'],
        ]);

        $this->travelTo(Carbon::parse('2026-10-31 15:00:00'));
    }

    private function agentUser(string $createdAt, ?string $lastOnlineAt = null, string $status = 'active'): User
    {
        $user = User::forceCreate([
            'name'           => 'Test Agent',
            'email'          => uniqid('agent', true) . '@example.com',
            'password'       => 'secret-password',
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

    private function inScope(User $user): bool
    {
        return User::whereKey($user->id)
            ->dormantSince(Carbon::today()->subDays(User::DORMANT_DAYS))
            ->exists();
    }

    /** [created_at, last_online_at, dormant?] */
    public static function cases(): array
    {
        return [
            'new account, never online'                => ['2026-10-29 09:00:00', null, false],
            'never online, signed up on last grace day' => ['2026-09-16 00:00:00', null, false],
            'never online, signed up the day before'   => ['2026-09-15 23:00:00', null, true],
            'never online since before the policy'     => ['2025-12-01 00:00:00', null, true],
            'old account, recent heartbeat'            => ['2025-12-01 00:00:00', '2026-10-25 08:00:00', false],
            'young account, stale heartbeat'           => ['2026-08-30 00:00:00', '2026-09-01 00:00:00', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_login_check_and_the_admin_scope_agree(string $createdAt, ?string $lastOnlineAt, bool $dormant): void
    {
        $user = $this->agentUser($createdAt, $lastOnlineAt);

        $this->assertSame($dormant, $this->inScope($user));

        $user->deactivateAgentIfDormant();

        $this->assertSame($dormant ? 'deactivated' : 'active', $this->statusOf($user));
    }

    public function test_an_account_with_no_signup_date_falls_back_to_the_policy_start(): void
    {
        $user = $this->agentUser('2026-10-29 09:00:00');
        DB::table('users')->where('id', $user->id)->update(['created_at' => null]);

        $this->assertTrue($this->inScope($user));

        $user->refresh()->deactivateAgentIfDormant();

        $this->assertSame('deactivated', $this->statusOf($user));
    }

    public function test_only_active_agents_are_touched(): void
    {
        $user = $this->agentUser('2025-12-01 00:00:00', null, 'inactive');

        $user->deactivateAgentIfDormant();

        $this->assertSame('inactive', $this->statusOf($user));
    }

    public function test_a_user_without_an_agent_row_is_left_alone(): void
    {
        $client = User::forceCreate([
            'name'       => 'Test Client',
            'email'      => 'client@example.com',
            'password'   => 'secret-password',
            'role_id'    => 3,
            'created_at' => '2025-12-01 00:00:00',
        ]);

        $client->deactivateAgentIfDormant();

        $this->assertSame(0, Agent::count());
    }

    public function test_a_new_agent_survives_its_first_password_login_and_lapses_after_45_idle_days(): void
    {
        $user = $this->agentUser(now()->toDateTimeString());

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret-password'])->assertOk();
        $this->assertSame('active', $this->statusOf($user));

        // Signing in isn't presence — only /authenticate and the session ping
        // write last_online_at — so an account that never loads the app lapses.
        $this->travel(50)->days();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'secret-password'])->assertOk();
        $this->assertSame('deactivated', $this->statusOf($user));
    }
}
