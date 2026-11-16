<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChatRateLimitService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * The 10-new-conversations-per-day cap. Usage is counted from the chats
 * rows themselves (a per-instance cache counter leaked on prod), over the
 * Philippine calendar day, and the 11th POST /chats is refused with a 429
 * and an audit row.
 */
class ChatDailyCapTest extends TestCase
{
    use CreatesAuthTables;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
            ['id' => 3, 'name' => 'client'],
        ]);
        Schema::create('chats', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('type_id');
            $t->string('source')->nullable();
            $t->string('origin_country')->nullable();
            $t->string('origin_region')->nullable();
            $t->string('origin_city')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('chat_id');
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('agent_user_id')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('conversation_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('conversation_id');
            $t->timestamp('last_read_at')->nullable();
            $t->timestamp('last_notified_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('removed_at')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('reply_to_id')->nullable();
            $t->string('type')->default('text');
            $t->text('body')->nullable();
            $t->json('attachments')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->longText('value')->nullable();
            $t->timestamps();
        });
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('blocked_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('blocked_user_id');
            $t->unsignedBigInteger('agent_user_id')->nullable();
            $t->string('scope')->default('per_agent');
            $t->timestamps();
        });

        Mail::fake();

        $this->client = User::forceCreate(['name' => 'Spammy Client', 'email' => 'spam@example.com', 'password' => 'x', 'role_id' => 3]);

        // Monday 09:00 Manila = Monday 01:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function chatAt(User $user, string $manilaTime, bool $trashed = false): void
    {
        $at = Carbon::parse($manilaTime, 'Asia/Manila')->utc();
        DB::table('chats')->insert([
            'type' => 'listing',
            'type_id' => DB::table('chats')->count() + 1000,
            'user_id' => $user->id,
            'created_at' => $at,
            'updated_at' => $at,
            'deleted_at' => $trashed ? $at : null,
        ]);
    }

    private function messageAgent(User $sender)
    {
        $agent = User::forceCreate(['name' => 'Agent '.uniqid(), 'email' => uniqid().'@agents.test', 'password' => 'x', 'role_id' => 2]);
        Sanctum::actingAs($sender);

        return $this->postJson('/api/chats', [
            'type' => 'agent',
            'type_id' => $agent->id,
            'target_user_id' => $agent->id,
            'message' => 'Hello, I would like to invest in your country.',
            'origin_country' => 'US',
        ]);
    }

    public function test_counts_only_chats_opened_since_philippine_midnight(): void
    {
        $this->chatAt($this->client, '2026-11-15 23:30'); // yesterday in PH
        $this->chatAt($this->client, '2026-11-16 00:30'); // today in PH, still "yesterday" in UTC
        $this->chatAt($this->client, '2026-11-16 08:30');
        $other = User::forceCreate(['name' => 'Someone Else', 'email' => 'else@example.com', 'password' => 'x', 'role_id' => 3]);
        $this->chatAt($other, '2026-11-16 08:45');

        $this->assertSame(2, app(ChatRateLimitService::class)->used($this->client->id));
    }

    public function test_deleted_chats_still_use_up_the_day(): void
    {
        foreach (range(1, 10) as $i) {
            $this->chatAt($this->client, '2026-11-16 08:0'.($i % 10), trashed: $i <= 5);
        }

        $this->assertTrue(app(ChatRateLimitService::class)->exhausted($this->client->id));
    }

    public function test_the_eleventh_new_conversation_is_refused_and_audited(): void
    {
        foreach (range(1, 9) as $i) {
            $this->chatAt($this->client, '2026-11-16 08:00');
        }

        $this->messageAgent($this->client)->assertSuccessful();
        $this->assertSame(10, DB::table('chats')->where('user_id', $this->client->id)->count());

        $this->messageAgent($this->client)
            ->assertStatus(429)
            ->assertJson(['limit' => 10, 'remaining' => 0]);
        $this->assertSame(10, DB::table('chats')->where('user_id', $this->client->id)->count());
        $this->assertTrue(DB::table('audits')
            ->where('event', 'rate_limit_hit')
            ->where('source', 'inquiry_daily_cap')
            ->where('user_id', $this->client->id)
            ->exists());
    }

    public function test_the_cap_resets_at_philippine_midnight(): void
    {
        foreach (range(1, 10) as $i) {
            $this->chatAt($this->client, '2026-11-15 20:00');
        }

        $this->messageAgent($this->client)->assertSuccessful();
    }

    public function test_admins_are_not_capped(): void
    {
        $admin = User::forceCreate(['name' => 'Moderator', 'email' => 'mod@example.com', 'password' => 'x', 'role_id' => 1]);
        foreach (range(1, 10) as $i) {
            $this->chatAt($admin, '2026-11-16 08:00');
        }

        $this->messageAgent($admin)->assertSuccessful();
    }
}
