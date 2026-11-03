<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * DELETE /api/users/{id} — admins only, and never their own account. The
 * route sits in the plain auth:sanctum group, and destroy() used to check
 * nothing, so any signed-in account (a client from the public OTP signup
 * included) could delete any user.
 */
class UserDestroyAuthorizationTest extends TestCase
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
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'name'     => ucfirst($role) . ' User',
            'email'    => uniqid($role, true) . '@example.com',
            'password' => 'secret',
            'role_id'  => DB::table('roles')->where('name', $role)->value('id'),
        ]);

        // Agents and secretaries always carry an active agent row in prod,
        // which is what agent.active looks at.
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

    public static function nonAdminRoles(): array
    {
        return [
            'agent'     => ['agent'],
            'client'    => ['client'],
            'editor'    => ['editor'],
            'secretary' => ['secretary'],
        ];
    }

    public function test_an_admin_can_delete_another_user(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $target = $this->user('client');

        $this->deleteJson("/api/users/{$target->id}")->assertOk();

        $this->assertNull(User::find($target->id));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_a_non_admin_cannot_delete_someone_else(string $role): void
    {
        Sanctum::actingAs($this->user($role));
        $target = $this->user('admin');

        $this->deleteJson("/api/users/{$target->id}")->assertForbidden();

        $this->assertNotNull(User::find($target->id));
    }

    public function test_a_non_admin_cannot_delete_their_own_account(): void
    {
        $client = $this->user('client');
        Sanctum::actingAs($client);

        $this->deleteJson("/api/users/{$client->id}")->assertForbidden();

        $this->assertNotNull(User::find($client->id));
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")->assertForbidden();

        $this->assertNotNull(User::find($admin->id));
    }

    public function test_a_non_admin_gets_403_not_404_for_an_unknown_id(): void
    {
        Sanctum::actingAs($this->user('client'));

        $this->deleteJson('/api/users/999999')->assertForbidden();
    }

    public function test_an_admin_gets_404_for_an_unknown_id(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->deleteJson('/api/users/999999')->assertNotFound();
    }

    public function test_a_guest_gets_401(): void
    {
        $target = $this->user('client');

        $this->deleteJson("/api/users/{$target->id}")->assertUnauthorized();

        $this->assertNotNull(User::find($target->id));
    }
}
