<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers agents:deactivate-dormant — the 45-day dormancy auto-deactivation
 * that starts counting at the 2026-07-02 policy start.
 *
 * The full migration suite contains MySQL-only raw SQL that can't run on the
 * sqlite test database, so instead of RefreshDatabase this builds just the
 * three tables the command touches.
 */
class DeactivateDormantAgentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('last_online_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAgent(string $status, ?string $lastLogin): Agent
    {
        $user = User::forceCreate([
            'name'     => 'Test Agent',
            'email'    => uniqid('', true) . '@example.com',
            'password' => 'secret',
        ]);

        if ($lastLogin !== null) {
            LoginLog::create([
                'user_id'      => $user->id,
                'logged_in_at' => Carbon::parse($lastLogin),
            ]);
        }

        return Agent::forceCreate([
            'user_id'    => $user->id,
            'first_name' => 'Test',
            'last_name'  => 'Agent',
            'status'     => $status,
        ]);
    }

    public function test_nothing_happens_during_the_initial_grace_window(): void
    {
        // 45 days after policy start is 2026-08-16; on that day the threshold
        // equals the policy start, so it must still be a no-op.
        Carbon::setTestNow('2026-08-16 08:00:00');

        $neverLoggedIn = $this->makeAgent('active', null);
        $ancientLogin  = $this->makeAgent('active', '2025-01-01 09:00:00');

        $this->artisan('agents:deactivate-dormant')->assertSuccessful();

        $this->assertSame('active', $neverLoggedIn->fresh()->status);
        $this->assertSame('active', $ancientLogin->fresh()->status);
    }

    public function test_dormant_agents_are_deactivated_after_the_window(): void
    {
        Carbon::setTestNow('2026-08-20 08:00:00'); // threshold = 2026-07-06

        $recentLogin   = $this->makeAgent('active', '2026-08-10 09:00:00');
        $staleLogin    = $this->makeAgent('active', '2026-07-01 09:00:00');
        $neverLoggedIn = $this->makeAgent('active', null);
        $resigned      = $this->makeAgent('resigned', '2026-01-01 09:00:00');

        $this->artisan('agents:deactivate-dormant')->assertSuccessful();

        $this->assertSame('active', $recentLogin->fresh()->status);
        $this->assertSame('deactivated', $staleLogin->fresh()->status);
        $this->assertSame('deactivated', $neverLoggedIn->fresh()->status);
        // Manually-set statuses are never overwritten by the dormancy sweep.
        $this->assertSame('resigned', $resigned->fresh()->status);
    }

    public function test_login_on_the_threshold_day_keeps_the_agent_active(): void
    {
        Carbon::setTestNow('2026-08-20 08:00:00'); // threshold = 2026-07-06

        $onThreshold = $this->makeAgent('active', '2026-07-06 00:00:00');

        $this->artisan('agents:deactivate-dormant')->assertSuccessful();

        $this->assertSame('active', $onThreshold->fresh()->status);
    }

    public function test_recent_heartbeat_counts_as_activity_even_without_login(): void
    {
        // Tokens never expire, so an agent can use the dashboard for months
        // without re-authenticating — the session-ping heartbeat must keep
        // them out of the sweep.
        Carbon::setTestNow('2026-08-20 08:00:00');

        $agent = $this->makeAgent('active', '2026-01-01 09:00:00');
        // forceFill: last_online_at isn't fillable (prod bumps it via a
        // query-builder update in sessionPing).
        $agent->user->forceFill(['last_online_at' => Carbon::parse('2026-08-18 09:00:00')])->save();

        $this->artisan('agents:deactivate-dormant')->assertSuccessful();

        $this->assertSame('active', $agent->fresh()->status);
    }

    public function test_recent_token_use_counts_as_activity_even_without_login(): void
    {
        // Covers the mobile app: it may never call session-ping, but Sanctum
        // bumps last_used_at on every API request.
        Carbon::setTestNow('2026-08-20 08:00:00');

        $agent = $this->makeAgent('active', null);
        $agent->user->tokens()->create([
            'name'      => 'iPhone',
            'token'     => hash('sha256', uniqid('', true)),
            'abilities' => ['*'],
        ])->forceFill([
            // forceFill: last_used_at isn't fillable on Sanctum's token model
            // (prod sets it internally on each authenticated request).
            'last_used_at' => Carbon::parse('2026-08-19 09:00:00'),
        ])->save();

        $this->artisan('agents:deactivate-dormant')->assertSuccessful();

        $this->assertSame('active', $agent->fresh()->status);
    }
}
