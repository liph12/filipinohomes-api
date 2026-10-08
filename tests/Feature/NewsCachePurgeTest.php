<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/news/{identifier}/purge-cache — the manual override for the
 * stacked-cache staleness NewsCachePurger's own docblock explains: an edit on
 * the external HomesPhNews CMS otherwise sits behind this server's 5-minute
 * article cache AND the frontend's 5-minute ISR on top of it.
 *
 * Builds its own minimal tables (roles/users/personal_access_tokens) — the
 * full migration suite is MySQL-only, same convention as NatconGalleryTrashTest.
 */
class NewsCachePurgeTest extends TestCase
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
    }

    private function actingAsRole(string $role): void
    {
        $roleId = Role::forceCreate(['name' => $role])->id;
        Sanctum::actingAs(User::forceCreate([
            'name' => 'Test User',
            'email' => $role.'@example.com',
            'password' => 'secret',
            'role_id' => $roleId,
        ]));
    }

    public function test_it_clears_the_cached_article_for_admins(): void
    {
        $this->actingAsRole('admin');
        Cache::put('homesphnews:article:weecomm-assembly', ['status' => 200, 'body' => ['article' => ['title' => 'stale']]], now()->addMinutes(5));

        $this->postJson('/api/news/weecomm-assembly/purge-cache')
            ->assertOk()
            ->assertJson(['purged' => true, 'identifier' => 'weecomm-assembly']);

        $this->assertNull(Cache::get('homesphnews:article:weecomm-assembly'));
    }

    public function test_editors_may_purge_it_too(): void
    {
        $this->actingAsRole('editor');
        Cache::put('homesphnews:article:weecomm-assembly', ['status' => 200, 'body' => []], now()->addMinutes(5));

        $this->postJson('/api/news/weecomm-assembly/purge-cache')->assertOk();

        $this->assertNull(Cache::get('homesphnews:article:weecomm-assembly'));
    }

    public function test_a_role_outside_admin_and_editor_is_refused(): void
    {
        $this->actingAsRole('agent');

        $this->postJson('/api/news/weecomm-assembly/purge-cache')->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->postJson('/api/news/weecomm-assembly/purge-cache')->assertUnauthorized();
    }

    public function test_it_asks_the_frontend_to_revalidate_the_article_path_when_configured(): void
    {
        config([
            'services.frontend.url' => 'https://frontend.test',
            'services.frontend.revalidation_secret' => 'test-secret',
        ]);
        Http::fake(['frontend.test/api/revalidate' => Http::response(['revalidated' => true])]);

        $this->actingAsRole('admin');
        $this->postJson('/api/news/weecomm-assembly/purge-cache')->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://frontend.test/api/revalidate'
                && $request->hasHeader('x-revalidation-token', 'test-secret')
                && $request['slug'] === 'news/weecomm-assembly'
                && $request['tags'] === ['news-weecomm-assembly'];
        });
    }

    public function test_it_stays_silent_when_the_frontend_is_not_configured(): void
    {
        config(['services.frontend.url' => '', 'services.frontend.revalidation_secret' => '']);

        $this->actingAsRole('admin');

        // No Http::fake() at all — a real outbound call here would fail the
        // test on its own (no network in CI), which is exactly the proof
        // that NewsCachePurger skips it rather than attempting one.
        $this->postJson('/api/news/weecomm-assembly/purge-cache')->assertOk();
    }
}
