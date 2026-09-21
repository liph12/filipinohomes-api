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
 * GET /api/my-listings — the agent-less-caller guard (same defect as
 * GET /api/user/dashboard: `$user->agent->id` on a user with no agents row).
 *
 * "My" listings must come back as a well-formed empty page, and must stay
 * empty even when other agents own listings — a null agent id fed straight to
 * `where('agent_id', ...)` compiles to `agent_id is null`, which would match
 * orphaned rows rather than nothing.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only.
 * Listing/Property fixtures go in through the query builder on purpose: the
 * Eloquent models carry creating/created/retrieved observers (slug + code
 * generation, IndexNow dispatch, created_by stamping) that would drag in
 * several more tables and have nothing to do with what is under test.
 */
class MyListingsAgentlessUserTest extends TestCase
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
            $table->decimal('price', 15, 2)->nullable();
            $table->string('visibility')->default('public');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->string('verification_status')->nullable();
            $table->text('featured_photo')->nullable();
            $table->text('photos_migration_note')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Named by the withCount(['inquiryChats as inquiries_count' => ...])
        // subquery on every call, even when there is nothing to count.
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('conversation_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('purged_at')->nullable();
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

    private function makeAgent(): Agent
    {
        return Agent::forceCreate([
            'user_id' => $this->makeUser('agent')->id,
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
            'ats_status' => 'approve',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('listings')->insertGetId([
            'name' => $attributes['name'] ?? 'Fixture Listing',
            'code' => $attributes['code'] ?? uniqid('FX-'),
            'slug' => $attributes['slug'] ?? uniqid('fixture-'),
            'price' => 1000000,
            'visibility' => $attributes['visibility'] ?? 'public',
            'is_featured' => $attributes['is_featured'] ?? false,
            'verification_status' => $attributes['verification_status'] ?? null,
            'clicks' => $attributes['clicks'] ?? 0,
            'property_id' => $propertyId,
            'category_id' => $this->categoryId($attributes['category'] ?? 'For Sale'),
            'agent_id' => $agentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** An accepted inquiry thread on a listing — what inquiries_count counts. */
    private function addInquiryChat(int $listingId, int $inquirerUserId): void
    {
        $chatId = DB::table('chats')->insertGetId([
            'type' => 'listing',
            'type_id' => $listingId,
            'user_id' => $inquirerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conversationId = DB::table('conversations')->insertGetId([
            'chat_id' => $chatId,
            'status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('conversation_users')->insert([
            'conversation_id' => $conversationId,
            'user_id' => $inquirerUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $counts */
    private function assertAllCountsZero(array $counts): void
    {
        foreach ($counts as $group => $values) {
            foreach ($values as $key => $value) {
                $this->assertSame(0, $value, "counts.{$group}.{$key} should be 0.");
            }
        }
    }

    // ── 7. the reported 500 ──────────────────────────────────────────────────

    public function test_agentless_client_gets_an_empty_page_instead_of_a_500(): void
    {
        Sanctum::actingAs($this->makeUser('client'));

        $response = $this->getJson('/api/my-listings?page=1&per_page=1');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertAllCountsZero($response->json('counts'));
    }

    // ── 8. empty must mean "none of MINE", not "none at all" ─────────────────

    public function test_agentless_client_does_not_see_another_agents_listings(): void
    {
        $other = $this->makeAgent();
        $first = $this->makeListing($other->id, ['clicks' => 15]);
        $this->makeListing($other->id, [
            'status' => 'sold',
            'visibility' => 'private',
            'is_featured' => true,
            'verification_status' => 'verified',
        ]);
        $this->makeListing($other->id, ['status' => 'rented', 'category' => 'For Rent']);

        $client = $this->makeUser('client');
        $this->addInquiryChat($first, $client->id);

        Sanctum::actingAs($client);

        $response = $this->getJson('/api/my-listings?page=1&per_page=1');

        $response->assertOk();
        // `where('agent_id', null)` → `agent_id is null` would leak orphaned
        // rows; an unscoped query would leak all three of these.
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertAllCountsZero($response->json('counts'));
    }
}
