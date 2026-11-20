<?php

namespace Tests\Feature;

use App\Mail\ReactivationApprovedMailer;
use App\Models\Agent;
use App\Models\ReactivationRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A deactivated agent asks for their account back; an admin accepts or
 * declines. Covers the agent routes getting through agent.active (the agent is
 * blocked), the one-pending-at-a-time and deactivated-only rules, and what
 * accepting actually does: status, dormancy clock, request state, email.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only —
 * but runs the real reactivation_requests migration.
 */
class ReactivationRequestTest extends TestCase
{
    private const REASON = 'I was on a long leave and could not log in; I will sign in every week from now on.';

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
            $table->string('avatar')->nullable();
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

        $migration = require database_path('migrations/2026_11_20_000001_create_reactivation_requests_table.php');
        $migration->up();
    }

    private function makeUser(string $roleName): User
    {
        $roleId = (Role::where('name', $roleName)->first() ?? Role::forceCreate(['name' => $roleName]))->id;

        return User::forceCreate([
            'name' => ucfirst($roleName).' User',
            'email' => uniqid('', true).'@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
            'last_online_at' => Carbon::now()->subDays(60),
        ]);
    }

    private function makeAgent(string $status = 'deactivated'): Agent
    {
        $user = $this->makeUser('agent');

        return Agent::forceCreate([
            'user_id' => $user->id,
            'first_name' => 'Donita',
            'last_name' => 'Dela Cruz',
            'status' => $status,
        ]);
    }

    private function pendingRequestFor(Agent $agent): ReactivationRequest
    {
        return ReactivationRequest::create([
            'agent_id' => $agent->id,
            'user_id' => $agent->user_id,
            'reason' => self::REASON,
            'status' => ReactivationRequest::PENDING,
        ]);
    }

    public function test_deactivated_agent_can_submit_a_request_through_agent_active(): void
    {
        $agent = $this->makeAgent();
        Sanctum::actingAs($agent->user);

        $this->postJson('/api/agent/reactivation-request', ['reason' => self::REASON])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('reactivation_requests', [
            'agent_id' => $agent->id,
            'user_id' => $agent->user_id,
            'status' => 'pending',
        ]);
    }

    public function test_second_request_while_one_is_pending_is_refused(): void
    {
        $agent = $this->makeAgent();
        $this->pendingRequestFor($agent);
        Sanctum::actingAs($agent->user);

        $this->postJson('/api/agent/reactivation-request', ['reason' => self::REASON])
            ->assertStatus(422);

        $this->assertSame(1, ReactivationRequest::where('agent_id', $agent->id)->count());
    }

    public function test_only_deactivated_agents_can_request(): void
    {
        foreach (['active', 'inactive', 'resigned', 'terminated'] as $status) {
            $agent = $this->makeAgent($status);
            Sanctum::actingAs($agent->user);

            // Blocked statuses are stopped by the controller (the route is
            // allowlisted through agent.active); active ones by the same check.
            $this->postJson('/api/agent/reactivation-request', ['reason' => self::REASON])
                ->assertStatus(422);
        }

        $this->assertSame(0, ReactivationRequest::count());
    }

    public function test_reason_is_required_and_must_say_something(): void
    {
        $agent = $this->makeAgent();
        Sanctum::actingAs($agent->user);

        $this->postJson('/api/agent/reactivation-request', [])->assertStatus(422);
        $this->postJson('/api/agent/reactivation-request', ['reason' => 'pls'])->assertStatus(422);
    }

    public function test_agent_sees_their_latest_request_or_null(): void
    {
        $agent = $this->makeAgent();
        Sanctum::actingAs($agent->user);

        $this->getJson('/api/agent/reactivation-request')->assertOk()->assertJsonPath('data', null);

        $this->pendingRequestFor($agent);

        $this->getJson('/api/agent/reactivation-request')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_approving_reactivates_the_agent_restarts_the_clock_and_emails_them(): void
    {
        Mail::fake();
        $agent = $this->makeAgent();
        $request = $this->pendingRequestFor($agent);
        $admin = $this->makeUser('admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/reactivation-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('active', $agent->fresh()->status);

        // Without this, the dormancy sweep re-deactivates them before they sign in.
        $this->assertTrue($agent->user->fresh()->last_online_at->gt(Carbon::now()->subMinute()));

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        Mail::assertSent(
            ReactivationApprovedMailer::class,
            fn ($mail) => $mail->hasTo($agent->user->email),
        );
    }

    public function test_approving_is_refused_once_the_agent_is_no_longer_deactivated(): void
    {
        Mail::fake();
        $agent = $this->makeAgent('terminated');
        $request = $this->pendingRequestFor($agent);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->postJson("/api/admin/reactivation-requests/{$request->id}/approve")
            ->assertStatus(422);

        $this->assertSame('terminated', $agent->fresh()->status);
        $this->assertSame('pending', $request->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_an_already_handled_request_cannot_be_approved_again(): void
    {
        Mail::fake();
        $agent = $this->makeAgent();
        $request = $this->pendingRequestFor($agent);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->postJson("/api/admin/reactivation-requests/{$request->id}/approve")->assertOk();
        $this->postJson("/api/admin/reactivation-requests/{$request->id}/approve")->assertStatus(422);

        Mail::assertSentCount(1);
    }

    public function test_declining_keeps_the_agent_deactivated_sends_no_email_and_allows_a_new_request(): void
    {
        Mail::fake();
        $agent = $this->makeAgent();
        $request = $this->pendingRequestFor($agent);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->postJson("/api/admin/reactivation-requests/{$request->id}/decline", ['admin_note' => 'Please contact your branch first.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $this->assertSame('deactivated', $agent->fresh()->status);
        $this->assertSame('Please contact your branch first.', $request->fresh()->admin_note);
        Mail::assertNothingSent();

        Sanctum::actingAs($agent->user);
        $this->postJson('/api/agent/reactivation-request', ['reason' => self::REASON])->assertCreated();
    }

    public function test_admin_list_filters_by_status_and_counts_pending(): void
    {
        $pendingAgent = $this->makeAgent();
        $this->pendingRequestFor($pendingAgent);

        $declinedAgent = $this->makeAgent();
        $declined = $this->pendingRequestFor($declinedAgent);
        $declined->update(['status' => ReactivationRequest::DECLINED]);

        Sanctum::actingAs($this->makeUser('admin'));

        $this->getJson('/api/admin/reactivation-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent.id', $pendingAgent->id);

        $this->getJson('/api/admin/reactivation-requests?status=declined')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/reactivation-requests?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/reactivation-requests/pending-count')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_admin_list_shows_the_users_photo_when_the_agent_has_none(): void
    {
        $agent = $this->makeAgent();
        $agent->user->forceFill(['avatar' => 'https://cdn.example.com/photo.jpg'])->save();
        $this->pendingRequestFor($agent);
        Sanctum::actingAs($this->makeUser('admin'));

        $this->getJson('/api/admin/reactivation-requests')
            ->assertOk()
            ->assertJsonPath('data.0.agent.avatar', 'https://cdn.example.com/photo.jpg')
            ->assertJsonPath('data.0.agent.email', $agent->user->email);
    }

    public function test_admin_routes_are_admin_only(): void
    {
        $agent = $this->makeAgent('active');
        $request = $this->pendingRequestFor($agent);
        Sanctum::actingAs($agent->user);

        $this->getJson('/api/admin/reactivation-requests')->assertForbidden();
        $this->postJson("/api/admin/reactivation-requests/{$request->id}/approve")->assertForbidden();
        $this->postJson("/api/admin/reactivation-requests/{$request->id}/decline")->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_an_admin_reactivation_counts_as_the_admins_decision_for_the_lr_sync(): void
    {
        $this->assertContains('reactivation_approval', Agent::ADMIN_STATUS_SOURCES);
        $this->assertContains('admin_status_dropdown', Agent::ADMIN_STATUS_SOURCES);
    }
}
