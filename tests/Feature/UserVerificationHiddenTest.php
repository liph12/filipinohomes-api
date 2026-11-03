<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * users.verification holds the live login code while a sign-in is pending.
 * Raw User models reach JSON in several places — GET /client-logins returned
 * every client's pending code to any signed-in account — so the column is
 * hidden from serialization. Server-side reads are unaffected.
 */
class UserVerificationHiddenTest extends TestCase
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
    }

    private function pendingClient(): User
    {
        $client = User::forceCreate([
            'name'         => 'Pending Client',
            'email'        => 'pending@example.com',
            'password'     => 'secret',
            'role_id'      => 3,
            'verification' => 'K7Q2ZP',
        ]);
        DB::table('user_info')->insert(['user_id' => $client->id, 'city' => 'Cebu City']);

        return $client;
    }

    public function test_user_json_never_carries_verification(): void
    {
        $client = $this->pendingClient();

        $this->assertArrayNotHasKey('verification', $client->toArray());
        $this->assertStringNotContainsString('K7Q2ZP', $client->toJson());
        $this->assertSame('K7Q2ZP', $client->fresh()->verification);
    }

    public function test_client_logins_does_not_leak_pending_codes(): void
    {
        $this->pendingClient();

        $agent = User::forceCreate([
            'name'     => 'Some Agent',
            'email'    => 'agent@example.com',
            'password' => 'secret',
            'role_id'  => 2,
        ]);
        Agent::forceCreate(['user_id' => $agent->id, 'first_name' => 'Some', 'last_name' => 'Agent']);
        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/client-logins')
            ->assertOk()
            ->assertJsonPath('0.email', 'pending@example.com')
            ->assertJsonMissingPath('0.verification');

        $this->assertStringNotContainsString('K7Q2ZP', $response->getContent());
    }
}
