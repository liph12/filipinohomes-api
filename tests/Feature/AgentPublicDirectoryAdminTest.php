<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Http\Controllers\PageBuilderController;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * Admins also work as agents, so an admin with a posted listing belongs on the
 * public agent pages like anyone else — but an admin with nothing posted does
 * not. Agent::scopeEligibleForPublicDirectory() is the single definition; the
 * directory, the "N agents online" pill, the agent page and the page sitemap
 * feed must all agree with it, and agents' own behaviour must not move.
 */
class AgentPublicDirectoryAdminTest extends TestCase
{
    use CreatesAuthTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAuthTables();
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'agent'],
            ['id' => 5, 'name' => 'secretary'],
        ]);

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('visibility')->default('public');
            $table->string('verification_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('page_builder', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->string('slug')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('team_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('agent_id');
            $table->boolean('is_leader')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    private function person(string $role, ?string $lastOnlineAt = null): Agent
    {
        $user = User::forceCreate([
            'name'           => ucfirst($role) . ' ' . uniqid(),
            'email'          => uniqid($role, true) . '@example.com',
            'password'       => 'secret',
            'role_id'        => DB::table('roles')->where('name', $role)->value('id'),
            'last_online_at' => $lastOnlineAt,
        ]);

        return Agent::forceCreate([
            'user_id'    => $user->id,
            'first_name' => ucfirst($role),
            'last_name'  => 'Person',
            'status'     => 'active',
        ]);
    }

    private function listing(Agent $agent, array $overrides = []): void
    {
        DB::table('listings')->insert(array_merge([
            'agent_id'   => $agent->id,
            'visibility' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function page(Agent $agent): int
    {
        return DB::table('page_builder')->insertGetId([
            'agent_id' => $agent->id,
            'slug'     => 'page-' . $agent->id,
            'title'    => 'Page',
        ]);
    }

    private function eligibleIds(): array
    {
        return Agent::eligibleForPublicDirectory()->orderBy('id')->pluck('id')->all();
    }

    private function directoryIds(array $query): array
    {
        $request = Request::create('/api/agents', 'GET', $query);

        return app(AgentController::class)->index($request)
            ->collection->pluck('id')->sort()->values()->all();
    }

    // ── The single definition ──────────────────────────────────────────────

    public function test_an_admin_is_eligible_only_once_they_have_a_publicly_listed_listing(): void
    {
        $posted = $this->person('admin');
        $this->listing($posted);

        $nothing = $this->person('admin');

        $privateOnly = $this->person('admin');
        $this->listing($privateOnly, ['visibility' => 'private']);

        $flaggedOnly = $this->person('admin');
        $this->listing($flaggedOnly, ['verification_status' => 'flagged']);

        $deletedOnly = $this->person('admin');
        $this->listing($deletedOnly, ['deleted_at' => now()]);

        $this->assertSame([$posted->id], $this->eligibleIds());
    }

    public function test_agents_are_eligible_regardless_and_secretaries_still_are_not(): void
    {
        $agentWithout = $this->person('agent');
        $agentWith = $this->person('agent');
        $this->listing($agentWith);

        // Secretaries never appeared in the directory; listing doesn't change that.
        $secretary = $this->person('secretary');
        $this->listing($secretary);

        $this->assertSame([$agentWithout->id, $agentWith->id], $this->eligibleIds());
    }

    // ── The public directory ───────────────────────────────────────────────

    public function test_the_public_directory_includes_admins_with_a_posted_listing_only(): void
    {
        $agent = $this->person('agent');
        $this->listing($agent);

        $postedAdmin = $this->person('admin');
        $this->listing($postedAdmin);

        $emptyAdmin = $this->person('admin');

        // Deliberately WITHOUT min_listings=1 (the real public callers send
        // it): its HAVING-without-GROUP-BY is MySQL-only, and leaving it out
        // proves the empty admin is excluded by the eligibility rule itself,
        // not by the listings-count filter.
        $ids = $this->directoryIds(['public' => 1]);

        $this->assertContains($agent->id, $ids);
        $this->assertContains($postedAdmin->id, $ids);
        $this->assertNotContains($emptyAdmin->id, $ids);
    }

    public function test_the_management_views_keep_the_explicit_role_split(): void
    {
        $agent = $this->person('agent');
        $admin = $this->person('admin');
        $this->listing($admin);

        // No `public` flag: the Agents table still shows agents only…
        $this->assertSame([$agent->id], $this->directoryIds([]));
        // …and include_admins still means "admins only".
        $this->assertSame([$admin->id], $this->directoryIds(['include_admins' => 1]));
    }

    // ── The "N agents online" pill ─────────────────────────────────────────

    public function test_the_online_pill_counts_an_online_admin_only_with_a_posted_listing(): void
    {
        $postedAdmin = $this->person('admin', now()->toDateTimeString());
        $this->listing($postedAdmin);

        // A PRIVATE listing, not none: the pill separately requires "has any
        // non-deleted listing", which would hide a listing-less admin on its
        // own. A private-only admin passes that clause, so only the
        // publicly-listed rule can keep them out — which is what's under test.
        $privateOnlyAdmin = $this->person('admin', now()->toDateTimeString());
        $this->listing($privateOnlyAdmin, ['visibility' => 'private']);

        $agent = $this->person('agent', now()->toDateTimeString());
        $this->listing($agent);

        $ids = app(AgentController::class)->onlineAgentIds()->getData(true)['ids'];

        $this->assertContains($postedAdmin->user_id, $ids);
        $this->assertContains($agent->user_id, $ids);
        $this->assertNotContains($privateOnlyAdmin->user_id, $ids);
    }

    // ── The agent page and the page sitemap feed ───────────────────────────

    public function test_an_admins_page_404s_for_the_public_until_they_post_a_listing(): void
    {
        $admin = $this->person('admin');
        $this->page($admin);

        try {
            app(PageBuilderController::class)->showByAgent((string) $admin->id);
            $this->fail('An admin with no posted listing must not have a public page.');
        } catch (NotFoundHttpException) {
            // expected
        }

        $this->listing($admin);
        $this->assertNotNull(app(PageBuilderController::class)->showByAgent((string) $admin->id));
    }

    public function test_an_agents_page_is_unaffected(): void
    {
        $agent = $this->person('agent');
        $this->page($agent);

        // No listings, still public — the admin rule must not leak onto agents.
        $this->assertNotNull(app(PageBuilderController::class)->showByAgent((string) $agent->id));
    }

    public function test_the_public_page_feed_drops_an_admins_page_the_public_would_404_on(): void
    {
        $agent = $this->person('agent');
        $agentPage = $this->page($agent);

        $emptyAdmin = $this->person('admin');
        $emptyAdminPage = $this->page($emptyAdmin);

        $pageIds = fn (array $query) => app(PageBuilderController::class)
            ->index(Request::create('/api/page/agents', 'GET', $query))
            ->collection->pluck('id')->sort()->values()->all();

        // The hub + page sitemap (public=1) must not list a page that 404s…
        $this->assertSame([$agentPage], $pageIds(['public' => 1]));
        // …while the admin Page List still shows every page.
        $this->assertSame([$agentPage, $emptyAdminPage], $pageIds([]));
    }
}
