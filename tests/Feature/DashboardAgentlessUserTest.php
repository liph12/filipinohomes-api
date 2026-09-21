<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/user/dashboard — the agent-less-caller guard.
 *
 * OTP client signup writes a `users` row (role_id 3) with no `agents` row, and
 * `agent.active` deliberately lets agent-less users through (see
 * EnsureAgentActiveTest::test_guest_and_agentless_user_pass), so such a user
 * reaches this controller. Before the fix `$user->agent->id` threw
 * "Attempt to read property \"id\" on null" and the endpoint 500'd.
 *
 * Also locks the two payload shapes that must NOT change: the real agent's
 * numbers and the admin branch's keys.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only.
 * Listing/Property fixtures go in through the query builder on purpose: the
 * Eloquent models carry creating/created/retrieved observers (slug + code
 * generation, IndexNow dispatch, created_by stamping) that would drag in
 * several more tables and have nothing to do with what is under test.
 */
class DashboardAgentlessUserTest extends TestCase
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
            $table->date('member_since')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('address')->nullable();
            $table->string('status')->default('active');
            $table->string('ats_status')->nullable();
            $table->date('ats_expiration_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->string('slug')->nullable();
            $table->string('visibility')->default('public');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('listing_inquiries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        // Left EMPTY on purpose: the admin branch's propertyStatistics() loops
        // over PropertySubtype::get(), so an empty table makes it a no-op and
        // keeps the whole property_types/property_attributes graph out of here.
        Schema::create('property_subtypes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('property_type_id')->nullable();
            $table->timestamps();
        });
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->value('id')
            ?? Role::forceCreate(['name' => $name])->id;
    }

    /** A signed-in user with NO agents row — what OTP client signup produces. */
    private function makeUser(string $roleName): User
    {
        return User::forceCreate([
            'name' => ucfirst($roleName).' User',
            'email' => uniqid('', true).'@example.com',
            'password' => 'secret',
            'role_id' => $this->roleId($roleName),
        ]);
    }

    private function makeAgent(string $roleName = 'agent'): Agent
    {
        return Agent::forceCreate([
            'user_id' => $this->makeUser($roleName)->id,
            'first_name' => 'Test',
            'last_name' => 'Agent',
            'status' => 'active',
            'member_since' => '2024-01-01',
        ]);
    }

    private function categoryId(string $name): int
    {
        return Category::where('name', $name)->value('id')
            ?? Category::forceCreate(['name' => $name])->id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeListing(int $agentId, array $attributes = []): int
    {
        $propertyId = DB::table('properties')->insertGetId([
            'address' => 'Cebu City, Cebu',
            'status' => $attributes['status'] ?? 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('listings')->insertGetId([
            'name' => $attributes['name'] ?? 'Fixture Listing',
            'code' => $attributes['code'] ?? uniqid('FX-'),
            'slug' => $attributes['slug'] ?? uniqid('fixture-'),
            'visibility' => $attributes['visibility'] ?? 'public',
            'is_featured' => false,
            'clicks' => $attributes['clicks'] ?? 0,
            'property_id' => $propertyId,
            'category_id' => $this->categoryId($attributes['category'] ?? 'For Sale'),
            'agent_id' => $agentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addInquiries(int $listingId, int $agentId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('listing_inquiries')->insert([
                'listing_id' => $listingId,
                'agent_id' => $agentId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ── 1. the reported 500 ──────────────────────────────────────────────────

    public function test_agentless_client_gets_a_zeroed_payload_instead_of_a_500(): void
    {
        Sanctum::actingAs($this->makeUser('client'));

        $response = $this->getJson('/api/user/dashboard');

        $response->assertOk();
        $this->assertSame([
            'active' => 0,
            'total' => 0,
            'rented' => 0,
            'sold' => 0,
            'inquiries' => 0,
            'views' => 0,
            'agents' => 0,
            'leased' => 0,
            'private_listings' => 0,
            'agent' => 0,
            'category' => ['For Sale' => 0, 'For Rent' => 0, 'Foreclosure' => 0],
        ], $response->json());
    }

    // ── 2. zeros must be "none of MINE", not "none at all" ───────────────────

    public function test_agentless_client_does_not_see_another_agents_listings(): void
    {
        $other = $this->makeAgent();
        $active = $this->makeListing($other->id, ['clicks' => 15]);
        $this->makeListing($other->id, ['status' => 'sold', 'clicks' => 25]);
        $this->makeListing($other->id, ['visibility' => 'private', 'category' => 'For Rent']);
        $this->addInquiries($active, $other->id, 1);

        Sanctum::actingAs($this->makeUser('client'));

        $response = $this->getJson('/api/user/dashboard');

        $response->assertOk();
        // Guards against "just drop the where clause": every one of these would
        // be non-zero if the query ran unscoped.
        $response->assertJson([
            'active' => 0,
            'total' => 0,
            'rented' => 0,
            'sold' => 0,
            'inquiries' => 0,
            'views' => 0,
            'leased' => 0,
            'private_listings' => 0,
            'agent' => 0,
            'category' => ['For Sale' => 0, 'For Rent' => 0, 'Foreclosure' => 0],
        ]);
    }

    // ── 3. the early return, not an accidental `agent_id IS NULL` ────────────

    public function test_agentless_call_issues_no_listings_query_at_all(): void
    {
        $other = $this->makeAgent();
        $this->makeListing($other->id);

        Sanctum::actingAs($this->makeUser('client'));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/user/dashboard')->assertOk();

        $listingQueries = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'listings')
        ));

        // `where('agent_id', null)` would compile to `agent_id is null` and
        // still hit the table (matching orphaned rows). The guard must return
        // before any listing query is built.
        $this->assertSame([], $listingQueries, 'Expected zero queries against `listings`.');
    }

    // ── 4. characterization lock: the real agent payload ────────────────────

    public function test_agent_payload_numbers_are_unchanged(): void
    {
        $agent = $this->makeAgent();
        $withInquiries = $this->makeListing($agent->id, ['clicks' => 10, 'category' => 'For Sale']);
        $this->makeListing($agent->id, ['clicks' => 20, 'category' => 'For Sale']);
        $this->makeListing($agent->id, ['status' => 'sold', 'clicks' => 30, 'category' => 'For Sale']);
        $this->makeListing($agent->id, [
            'status' => 'rented',
            'clicks' => 40,
            'category' => 'For Rent',
            'visibility' => 'private',
        ]);
        $this->addInquiries($withInquiries, $agent->id, 2);

        // A second agent's listing that must not leak into the numbers.
        $this->makeListing($this->makeAgent()->id, ['clicks' => 999]);

        Sanctum::actingAs($agent->user);

        $response = $this->getJson('/api/user/dashboard');

        $response->assertOk();
        $this->assertSame([
            'active' => 2,
            'total' => 4,
            'rented' => 1,
            'sold' => 1,
            'inquiries' => 2,
            'views' => 100,
            'agents' => 0,
            'leased' => 0,
            'private_listings' => 1,
            'agent' => 1,
            'category' => ['For Sale' => 3, 'For Rent' => 1, 'Foreclosure' => 0],
        ], $response->json());
    }

    // ── 5. characterization lock: the admin payload ─────────────────────────

    public function test_admin_payload_keys_are_unchanged(): void
    {
        $admin = $this->makeAgent('admin');
        $this->makeListing($admin->id, ['visibility' => 'private']);
        $this->makeListing($admin->id);

        Sanctum::actingAs($admin->user);

        $response = $this->getJson('/api/user/dashboard');

        $response->assertOk();
        $this->assertSame(
            ['agents', 'properties', 'private_listings'],
            array_keys($response->json())
        );
        $this->assertSame(1, $response->json('agents'));
        $this->assertSame(1, $response->json('private_listings'));
        $this->assertSame([], $response->json('properties.data'));
        // The agent-less guard's zeroed keys must never appear on the admin
        // payload — that would mean the guard sits above the admin return.
        $response->assertJsonMissingPath('agent');
        $response->assertJsonMissingPath('category');
    }

    // ── 6. admins are not fenced behind having an agents row ────────────────

    public function test_admin_without_an_agents_row_still_gets_the_admin_payload(): void
    {
        $admin = $this->makeUser('admin');
        $this->assertNull($admin->agent, 'This admin must have no agents row.');
        $this->makeListing($this->makeAgent()->id, ['visibility' => 'private']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/user/dashboard');

        $response->assertOk();
        // Would fail if the guard ran before the admin branch: the admin would
        // get the zeroed agent payload and lose `properties` entirely.
        $this->assertSame(
            ['agents', 'properties', 'private_listings'],
            array_keys($response->json())
        );
        $this->assertSame(1, $response->json('private_listings'));
    }
}
