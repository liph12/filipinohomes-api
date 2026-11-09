<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\Agent;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * Impersonation must stay a GHOST: while an admin operates an agent's account,
 * nothing may be stamped onto that agent's activity record. Concretely, the
 * admin's presence must never reach the two stats the admin Agents table
 * shows — "Last online" (users.last_online_at) and "Total Logins" (a COUNT
 * over login_logs).
 *
 * The protection is easy to break because it is implicit: the impersonation
 * path simply never calls LoginLog::record(), and the only two endpoints that
 * write last_online_at (authenticate + sessionPing) are gated on
 * Impersonation::isImpersonated(). Remove either gate and nothing fails
 * loudly — the agent just quietly starts looking online. These tests are that
 * guard-rail.
 *
 * Each ghost assertion is paired with a NORMAL-session control proving the
 * same endpoint DOES write when it should. Without the control, an endpoint
 * that silently became a no-op for everyone would pass just as happily.
 */
class ImpersonationGhostTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
        ]);

        // UserResource (returned by authenticate()) embeds an agent's
        // page-builder slug + team membership, so these must exist for an
        // agent target. Empty is fine.
        Schema::create('page_builder', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id');
            $table->string('slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('team_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('agent_id');
            $table->boolean('is_leader')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'name'     => ucfirst($role) . ' Person',
            'email'    => uniqid($role, true) . '@example.com',
            'password' => 'secret',
            'role_id'  => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        if ($role === 'agent') {
            Agent::forceCreate([
                'user_id'    => $user->id,
                'first_name' => 'Test',
                'last_name'  => 'Agent',
                'status'     => 'active',
            ]);
        }

        return $user;
    }

    /**
     * Attach a real PersonalAccessToken as the user's CURRENT token.
     *
     * Sanctum's bearer auth doesn't populate currentAccessToken() under the
     * test harness, so hitting the HTTP route would never exercise the
     * impersonation branch (isImpersonated() reads that token). Driving the
     * controller directly with a real token attached is the same approach
     * ImpersonationByUserTest uses for stop().
     */
    private function actingWithToken(User $user, string $tokenName): User
    {
        $user->withAccessToken($user->createToken($tokenName)->accessToken);

        return $user;
    }

    private function impersonationTokenName(User $admin): string
    {
        return Impersonation::tokenName($admin->id);
    }

    private function sessionPingAs(User $user): void
    {
        $request = Request::create('/api/user/session-ping', 'POST');
        $request->setUserResolver(fn () => $user);

        (new UserController())->sessionPing($request);
    }

    private function authenticateAs(User $user): void
    {
        $request = Request::create('/api/authenticate', 'GET');
        $request->setUserResolver(fn () => $user);

        (new UserController())->authenticate($request);
    }

    // ── Starting an impersonation leaves no trace ──────────────────────────

    public function test_starting_an_impersonation_records_no_login_and_no_presence(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);

        $target = $this->user('agent');
        $agentId = Agent::where('user_id', $target->id)->value('id');

        $this->postJson("/api/admin/agents/{$agentId}/impersonate")->assertOk();

        // "Total Logins" is COUNT(*) over login_logs — impersonating is not a login.
        $this->assertSame(0, DB::table('login_logs')->where('user_id', $target->id)->count());

        // "Last online" / presence must be untouched.
        $fresh = $target->fresh();
        $this->assertNull($fresh->last_online_at);
        $this->assertNull($fresh->active_at);
    }

    // ── The 60s heartbeat ──────────────────────────────────────────────────

    public function test_session_ping_keeps_an_impersonated_session_offline(): void
    {
        $admin = $this->user('admin');
        $target = $this->actingWithToken($this->user('agent'), $this->impersonationTokenName($admin));

        $this->sessionPingAs($target);

        $this->assertNull($target->fresh()->last_online_at);
    }

    public function test_session_ping_bumps_presence_for_a_normal_session(): void
    {
        $user = $this->actingWithToken($this->user('agent'), 'web-login');

        $this->sessionPingAs($user);

        $this->assertNotNull(
            $user->fresh()->last_online_at,
            'Control failed: sessionPing no longer writes presence for a NORMAL session, '
            . 'so the impersonation test above proves nothing.',
        );
    }

    // ── /authenticate, which fires on every app load ───────────────────────

    public function test_authenticate_keeps_an_impersonated_session_offline(): void
    {
        $admin = $this->user('admin');
        $target = $this->actingWithToken($this->user('agent'), $this->impersonationTokenName($admin));

        $this->authenticateAs($target);

        $fresh = $target->fresh();
        $this->assertNull($fresh->last_online_at);
        $this->assertNull($fresh->active_at);
    }

    public function test_authenticate_bumps_presence_for_a_normal_session(): void
    {
        $user = $this->actingWithToken($this->user('agent'), 'web-login');

        $this->authenticateAs($user);

        $this->assertNotNull(
            $user->fresh()->last_online_at,
            'Control failed: authenticate no longer writes presence for a NORMAL session, '
            . 'so the impersonation test above proves nothing.',
        );
    }

    // ── The predicate the whole guard rests on ─────────────────────────────

    public function test_only_a_name_marked_token_counts_as_impersonation(): void
    {
        $admin = $this->user('admin');

        $impersonated = $this->actingWithToken($this->user('agent'), $this->impersonationTokenName($admin));
        $this->assertTrue(Impersonation::isImpersonated($impersonated));

        $normal = $this->actingWithToken($this->user('agent'), 'web-login');
        $this->assertFalse(Impersonation::isImpersonated($normal));
    }
}
