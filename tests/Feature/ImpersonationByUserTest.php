<?php

namespace Tests\Feature;

use App\Http\Controllers\ImpersonationController;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * POST /api/admin/users/{user}/impersonate — the System Users "Open account".
 * An admin opens ANY non-admin user's account; the existing agent-only
 * "Log in as agent" engine is reused, so the session is name-marked, kept
 * offline, and audited under the admin.
 */
class ImpersonationByUserTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
            ['id' => 3, 'name' => 'client'],
            ['id' => 4, 'name' => 'editor'],
            ['id' => 5, 'name' => 'secretary'],
        ]);

        // UserResource embeds an agent's page-builder slug + team membership,
        // so these must exist (empty is fine) when the target has an agent row.
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

        if (in_array($role, ['agent', 'secretary'], true)) {
            Agent::forceCreate([
                'user_id'    => $user->id,
                'first_name' => 'Test',
                'last_name'  => ucfirst($role),
                'status'     => 'active',
                'region'     => $role === 'secretary' ? 'cebu' : null,
            ]);
        }

        return $user;
    }

    private function open(User $target)
    {
        return $this->postJson("/api/admin/users/{$target->id}/impersonate");
    }

    public static function impersonatableRoles(): array
    {
        return ['agent' => ['agent'], 'client' => ['client'], 'editor' => ['editor'], 'secretary' => ['secretary']];
    }

    #[DataProvider('impersonatableRoles')]
    public function test_an_admin_can_open_any_non_admin_account(string $role): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);
        $target = $this->user($role);

        $response = $this->open($target)->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertSame($target->email, $response->json('user.email'));

        // The minted token lives on the TARGET and is name-marked with the admin id.
        $token = DB::table('personal_access_tokens')
            ->where('tokenable_id', $target->id)
            ->where('tokenable_type', User::class)
            ->first();
        $this->assertNotNull($token);
        $this->assertSame("admin-impersonation:{$admin->id}", $token->name);

        // One audit row: actor = admin, subject = target.
        $audit = DB::table('audits')->where('event', 'admin_impersonated')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, (int) $audit->user_id);
        $this->assertSame($target->id, (int) $audit->auditable_id);
        $this->assertSame('impersonation', $audit->source);
    }

    #[DataProvider('impersonatableRoles')]
    public function test_a_non_admin_cannot_open_an_account(string $role): void
    {
        Sanctum::actingAs($this->user($role));

        $this->open($this->user('client'))->assertForbidden();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_an_admin_cannot_open_their_own_account(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);

        $this->open($admin)->assertStatus(422);
    }

    public function test_an_admin_cannot_open_another_admin(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->open($this->user('admin'))->assertForbidden();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_guest_gets_401(): void
    {
        $this->open($this->user('client'))->assertUnauthorized();
    }

    public function test_return_from_impersonation_revokes_the_token_and_is_audited(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('client');

        // Start via the endpoint, then drive stop() directly with the minted
        // token attached as the current access token. (Sanctum's bearer auth
        // doesn't populate currentAccessToken() under the test harness, so the
        // HTTP route can't exercise the impersonation branch; this drives the
        // same controller logic with a real PersonalAccessToken.)
        Sanctum::actingAs($admin);
        $this->open($target)->assertOk();
        $pat = PersonalAccessToken::where('tokenable_id', $target->id)->firstOrFail();
        $target->withAccessToken($pat);

        $request = Request::create('/api/admin/impersonate/stop', 'POST');
        $request->setUserResolver(fn () => $target);
        (new ImpersonationController())->stop($request);

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
        $this->assertSame(1, DB::table('audits')->where('event', 'returned_from_impersonation')->count());
    }
}
