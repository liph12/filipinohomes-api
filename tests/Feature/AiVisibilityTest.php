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
        DB::table('listings')->insert(['name' => 'Cafe ice top shop for sale', 'slug' => 'cafe-for-sale']);

        $ai = app(AiVisibilityService::class);
        $ai->record('view', 'chatgpt', '/cafe-for-sale', 'ChatGPT-User');
        $ai->record('view', 'chatgpt', '/cafe-for-sale', 'ChatGPT-User');
        $ai->record('click', 'perplexity', '/cafe-for-sale');
        $ai->record('crawl', 'chatgpt', '/for-sale/house', 'OAI-SearchBot');

        $res = $this->getJson('/api/admin/ai-visibility')->assertOk();

        $res->assertJsonPath('totals', ['view' => 2, 'crawl' => 1, 'click' => 1]);
        $res->assertJsonPath('top_pages.0.path', '/cafe-for-sale');
        $res->assertJsonPath('top_pages.0.title', 'Cafe ice top shop for sale');
        $res->assertJsonPath('top_pages.0.views', 2);
        $res->assertJsonPath('top_pages.0.clicks', 1);
        $res->assertJsonPath('top_pages.1.title', null);
        $this->assertSame(['chatgpt', 'perplexity'], array_column($res->json('sources'), 'source'));
    }
}
