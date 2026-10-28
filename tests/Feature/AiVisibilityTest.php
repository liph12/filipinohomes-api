<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Audience\AiVisibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI-assistant visibility: live fetches (proxy.ts → /track/ai-fetch),
 * clicks (VisitTracker → /track/ai-click) and the admin report.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only.
 */
class AiVisibilityTest extends TestCase
{
    private const CHATGPT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.build_token' => 'build-secret']);

        Schema::create('ai_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8);
            $table->string('source', 32);
            $table->string('agent', 64)->nullable();
            $table->string('path', 512);
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->json('featured_photo')->nullable();
            $table->timestamp('photos_variants_generated_at')->nullable();
            $table->softDeletes();
        });
    }

    private function actingAsAdmin(): void
    {
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
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        $roleId = Role::forceCreate(['name' => 'admin'])->id;
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
        ]));
    }

    public function test_classifies_agents_and_clicks(): void
    {
        $ai = app(AiVisibilityService::class);

        $this->assertSame(['kind' => 'view', 'source' => 'chatgpt', 'agent' => 'ChatGPT-User'], $ai->classifyAgent(self::CHATGPT_UA));
        $this->assertSame('crawl', $ai->classifyAgent('Mozilla/5.0; compatible; OAI-SearchBot/1.0')['kind']);
        $this->assertSame('perplexity', $ai->classifyAgent('Mozilla/5.0 (compatible; Perplexity-User/1.0)')['source']);
        $this->assertNull($ai->classifyAgent('Mozilla/5.0 (compatible; Googlebot/2.1)'));

        $this->assertSame('chatgpt', $ai->classifyClick('chatgpt.com', null));
        $this->assertSame('gemini', $ai->classifyClick(null, 'https://gemini.google.com/app'));
        $this->assertSame('perplexity', $ai->classifyClick(null, 'https://www.perplexity.ai/search?q=x'));
        $this->assertNull($ai->classifyClick(null, 'https://www.google.com/'));
        $this->assertNull($ai->classifyClick('facebook', null));
    }

    public function test_fetch_requires_the_build_token_and_records_known_agents_only(): void
    {
        $this->postJson('/api/track/ai-fetch', ['path' => '/cafe-for-sale', 'user_agent' => self::CHATGPT_UA])
            ->assertStatus(401)->assertJson(['error' => 'Unauthorized']);

        $this->withHeader('X-Build-Token', 'build-secret')
            ->postJson('/api/track/ai-fetch', ['path' => '/cafe-for-sale/?utm=x', 'user_agent' => self::CHATGPT_UA])
            ->assertOk()->assertJson(['kind' => 'view', 'source' => 'chatgpt']);

        $this->withHeader('X-Build-Token', 'build-secret')
            ->postJson('/api/track/ai-fetch', ['path' => '/x', 'user_agent' => 'curl/8.0'])
            ->assertOk()->assertJson(['ignored' => true]);

        $this->assertSame(1, DB::table('ai_events')->count());
        $this->assertSame('/cafe-for-sale', DB::table('ai_events')->value('path'));
    }

    public function test_click_is_recorded_once_per_visitor_page_and_day(): void
    {
        $payload = ['visitor_id' => 'v1', 'utm_source' => 'chatgpt.com', 'path' => '/cafe-for-sale'];

        $this->postJson('/api/track/ai-click', $payload)->assertOk()->assertJson(['source' => 'chatgpt']);
        $this->postJson('/api/track/ai-click', $payload)->assertOk()->assertJson(['deduped' => true]);
        $this->postJson('/api/track/ai-click', ['visitor_id' => 'v1', 'referrer' => 'https://www.google.com/', 'path' => '/x'])
            ->assertOk()->assertJson(['ignored' => true]);

        $this->assertSame(1, DB::table('ai_events')->where('kind', 'click')->count());
    }

    public function test_report_totals_sources_and_names_listing_pages(): void
    {
        $this->actingAsAdmin();
        DB::table('listings')->insert([
            'name' => 'Cafe ice top shop for sale',
            'slug' => 'cafe-for-sale',
            'featured_photo' => json_encode(['https://example.com/cafe.webp']),
        ]);

        $ai = app(AiVisibilityService::class);
        $ai->record('view', 'chatgpt', '/cafe-for-sale', 'ChatGPT-User');
        $ai->record('view', 'chatgpt', '/cafe-for-sale', 'ChatGPT-User');
        $ai->record('click', 'perplexity', '/cafe-for-sale');
        $ai->record('crawl', 'chatgpt', '/for-sale/house', 'OAI-SearchBot');

        $res = $this->getJson('/api/admin/ai-visibility')->assertOk();

        $res->assertJsonPath('totals', ['view' => 2, 'crawl' => 1, 'click' => 1]);
        $res->assertJsonPath('top_pages.0.path', '/cafe-for-sale');
        $res->assertJsonPath('top_pages.0.title', 'Cafe ice top shop for sale');
        $res->assertJsonPath('top_pages.0.photo', 'https://example.com/cafe.webp');
        $res->assertJsonPath('top_pages.0.views', 2);
        $res->assertJsonPath('top_pages.0.clicks', 1);
        $res->assertJsonPath('top_pages.1.title', null);
        $this->assertSame(['chatgpt', 'perplexity'], array_column($res->json('sources'), 'source'));
        $res->assertJsonPath('top_pages_meta', ['page' => 1, 'per_page' => 50, 'total' => 2, 'last_page' => 1, 'sort' => 'views']);
    }

    public function test_top_pages_paginate_and_sort(): void
    {
        $this->actingAsAdmin();
        $ai = app(AiVisibilityService::class);
        foreach (range(1, 12) as $i) {
            $ai->record('view', 'chatgpt', "/page-{$i}", 'ChatGPT-User');
        }
        $ai->record('click', 'gemini', '/page-7');
        $ai->record('view', 'chatgpt', '/Page-3', 'ChatGPT-User'); // same page, other case

        $first = $this->getJson('/api/admin/ai-visibility?per_page=10')->assertOk();
        $first->assertJsonPath('top_pages_meta.total', 12);
        $first->assertJsonPath('top_pages_meta.last_page', 2);
        $first->assertJsonPath('top_pages.0.path', '/page-3');
        $this->assertCount(10, $first->json('top_pages'));

        $this->getJson('/api/admin/ai-visibility?per_page=10&page=2')->assertOk()
            ->assertJsonCount(2, 'top_pages');

        $this->getJson('/api/admin/ai-visibility?per_page=10&sort=clicks')->assertOk()
            ->assertJsonPath('top_pages.0.path', '/page-7');
    }

    private function createVisitsTable(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->nullable();
            $table->string('channel', 32)->default('direct');
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('landing_path', 512)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('country', 64)->nullable();
            $table->string('region', 96)->nullable();
            $table->string('city', 96)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_visit_channel_is_the_assistant_itself(): void
    {
        $this->createVisitsTable();

        $this->postJson('/api/track/visit', ['utm_source' => 'chatgpt.com'])->assertJson(['channel' => 'chatgpt']);
        $this->postJson('/api/track/visit', ['referrer' => 'https://gemini.google.com/app'])->assertJson(['channel' => 'gemini']);
        $this->postJson('/api/track/visit', ['referrer' => 'https://www.google.com/'])->assertJson(['channel' => 'google']);
        $this->postJson('/api/track/visit', ['referrer' => 'https://l.messenger.com/'])->assertJson(['channel' => 'facebook']);

        // One row per visitor per channel per day: a second source the same day counts, a repeat doesn't.
        $this->postJson('/api/track/visit', ['visitor_id' => 'dev-1', 'referrer' => 'https://www.google.com/'])->assertJson(['channel' => 'google']);
        $this->postJson('/api/track/visit', ['visitor_id' => 'dev-1', 'utm_source' => 'chatgpt.com'])->assertJson(['channel' => 'chatgpt']);
        $this->postJson('/api/track/visit', ['visitor_id' => 'dev-1', 'referrer' => 'https://www.google.com/'])->assertJson(['deduped' => true]);
        $this->assertSame(2, DB::table('visits')->where('visitor_id', 'dev-1')->count());
    }

    public function test_visit_records_the_logged_in_user_from_the_bearer_token(): void
    {
        $this->createVisitsTable();
        $this->actingAsAdmin();
        // A real bearer token on a public route, as the browser sends it — not actingAs, which also
        // switches the default guard and would hide the bug.
        $token = User::first()->createToken('t')->plainTextToken;
        config(['auth.defaults.guard' => 'web']); // actingAs set it to sanctum; prod's public route uses web
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/track/visit', ['visitor_id' => 'dev-auth', 'referrer' => 'https://www.google.com/'])->assertOk();

        $this->assertNotNull(DB::table('visits')->where('visitor_id', 'dev-auth')->value('user_id'));
    }

    public function test_referral_breakdown_names_sites_apps_and_tags(): void
    {
        $this->createVisitsTable();
        Schema::create('roles', fn (Blueprint $t) => $t->id() && $t->string('name'));
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id')->nullable();
            $t->string('visitor_id', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('login_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->timestamp('logged_in_at')->nullable();
        });

        $this->postJson('/api/track/visit', ['visitor_id' => 'r-1', 'referrer' => 'https://www.lamudi.com.ph/listing/1'])->assertJson(['channel' => 'referral']);
        $this->postJson('/api/track/visit', ['visitor_id' => 'r-2', 'referrer' => 'https://lamudi.com.ph/'])->assertJson(['channel' => 'referral']);
        $this->postJson('/api/track/visit', ['visitor_id' => 'r-3', 'referrer' => 'android-app://com.linkedin.android/'])->assertJson(['channel' => 'referral']);
        $this->postJson('/api/track/visit', ['visitor_id' => 'r-4', 'utm_source' => 'partnersite'])->assertJson(['channel' => 'referral']);

        $refs = collect(app(\App\Services\Audience\TrafficSourceService::class)
            ->range(now()->subDay()->toDateString(), now()->toDateString())
            ->channels()['referrers'])->keyBy('name');

        $this->assertSame(['name' => 'lamudi.com.ph', 'kind' => 'site', 'people' => 2, 'visits' => 2], $refs['lamudi.com.ph']);
        $this->assertSame('app', $refs['LinkedIn app']['kind']);
        $this->assertSame('tag', $refs['partnersite']['kind']);

        // One of the four devices also browsed logged in → 3 anonymous out of 4 unique.
        DB::table('roles')->insert(['id' => 1, 'name' => 'client']);
        DB::table('users')->insert(['id' => 99, 'role_id' => 1, 'created_at' => now()->subYear()]);
        DB::table('visits')->insert(['visitor_id' => 'r-4', 'channel' => 'direct', 'user_id' => 99, 'created_at' => now()]);
        $acq = app(\App\Services\Audience\TrafficSourceService::class)
            ->range(now()->subDay()->toDateString(), now()->toDateString())->channels();
        $this->assertSame(4, $acq['unique_visitors']);
        $this->assertSame(3, $acq['anonymous_visitors']);

        // A device saved on an account at signup isn't anonymous, even with no logged-in visit (old rows).
        DB::table('users')->insert(['id' => 100, 'role_id' => 1, 'visitor_id' => 'r-1', 'created_at' => now()->subYear()]);
        $this->assertSame(2, app(\App\Services\Audience\TrafficSourceService::class)
            ->range(now()->subDay()->toDateString(), now()->toDateString())->channels()['anonymous_visitors']);
    }
}
