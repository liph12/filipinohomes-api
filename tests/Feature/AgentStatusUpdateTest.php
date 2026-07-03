<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PATCH /api/agents/{id}/status — the manual "deactivated" guard: an agent
 * who logged in within the last 45 days can't be marked deactivated (that
 * status is reserved for dormancy); other statuses stay unrestricted.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only.
 */
class AgentStatusUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable();
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

        // AgentResource (returned on success) resolves the agent's
        // page-builder slug and team membership, so these tables must
        // exist even when empty.
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

    private function makeUser(string $roleName): User
    {
        $roleId = \App\Models\Role::forceCreate(['name' => $roleName])->id;

        return User::forceCreate([
            'name'     => ucfirst($roleName) . ' User',
            'email'    => uniqid('', true) . '@example.com',
            'password' => 'secret',
            'role_id'  => $roleId,
        ]);
    }

    private function makeAgent(?string $lastLogin): Agent
    {
        $user = $this->makeUser('agent');

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
            'status'     => 'active',
        ]);
    }

    public function test_cannot_deactivate_agent_with_recent_login(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $agent = $this->makeAgent(Carbon::now()->subDays(10)->toDateTimeString());

        $response = $this->patchJson("/api/agents/{$agent->id}/status", ['status' => 'deactivated']);

        $response->assertStatus(422);
        $this->assertSame('active', $agent->fresh()->status);
    }

    public function test_can_deactivate_agent_whose_last_login_is_older_than_45_days(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $agent = $this->makeAgent(Carbon::now()->subDays(60)->toDateTimeString());

        $this->patchJson("/api/agents/{$agent->id}/status", ['status' => 'deactivated'])
            ->assertOk();

        $this->assertSame('deactivated', $agent->fresh()->status);
    }

    public function test_can_deactivate_agent_who_never_logged_in(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $agent = $this->makeAgent(null);

        $this->patchJson("/api/agents/{$agent->id}/status", ['status' => 'deactivated'])
            ->assertOk();

        $this->assertSame('deactivated', $agent->fresh()->status);
    }

    public function test_cannot_deactivate_agent_with_recent_heartbeat_even_without_login(): void
    {
        // Long-lived token, no re-authentication — activity shows up only in
        // last_online_at (session-ping) and must still block deactivation.
        Sanctum::actingAs($this->makeUser('admin'));
        $agent = $this->makeAgent(null);
        // forceFill: last_online_at isn't fillable (prod bumps it via a
        // query-builder update in sessionPing).
        $agent->user->forceFill(['last_online_at' => Carbon::now()->subDays(3)])->save();

        $this->patchJson("/api/agents/{$agent->id}/status", ['status' => 'deactivated'])
            ->assertStatus(422);

        $this->assertSame('active', $agent->fresh()->status);
    }

    public function test_other_statuses_are_not_restricted_by_recent_login(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $agent = $this->makeAgent(Carbon::now()->subDays(2)->toDateTimeString());

        $this->patchJson("/api/agents/{$agent->id}/status", ['status' => 'inactive'])
            ->assertOk();

        $this->assertSame('inactive', $agent->fresh()->status);
    }

    public function test_non_admin_cannot_change_status_at_all(): void
    {
        Sanctum::actingAs($this->makeUser('agent'));
        $agent = $this->makeAgent(null);

        $this->patchJson("/api/agents/{$agent->id}/status", ['status' => 'deactivated'])
            ->assertStatus(403);

        $this->assertSame('active', $agent->fresh()->status);
    }
}
