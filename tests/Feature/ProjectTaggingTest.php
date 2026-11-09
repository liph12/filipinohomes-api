<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Project\ProjectTagAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin Project tagging tab: the flagged listings come back judged, one click tags a listing to
 * the project (and the activity log says so), "not this project" hides the pair, and agents are kept out.
 */
class ProjectTaggingTest extends TestCase
{
    private User $admin;

    private User $agentUser;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->foreignId('role_id')->nullable();
            $t->timestamps();
        });
        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('complete_address')->nullable();
            $t->integer('views')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('address')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->boolean('is_project')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->decimal('price', 14, 2)->nullable();
            $t->text('featured_photo')->nullable();
            $t->string('visibility')->default('public');
            $t->string('verification_status')->nullable();
            $t->foreignId('category_id');
            $t->foreignId('agent_id');
            $t->foreignId('property_id');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('project_tag_dismissals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_id');
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
            $t->unique(['listing_id', 'project_id']);
        });
        Schema::create('audits', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('user_type')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('event');
            $t->morphs('auditable');
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->text('url')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('user_agent', 1023)->nullable();
            $t->string('tags')->nullable();
            $t->string('category', 64)->nullable();
            $t->string('source', 64)->nullable();
            $t->string('client', 16)->nullable();
            $t->string('device_platform', 16)->nullable();
            $t->string('subject_label')->nullable();
            $t->string('user_role', 32)->nullable();
            $t->string('user_name', 191)->nullable();
            $t->string('description', 500)->nullable();
            $t->timestamps();
        });
        Model::unguard();
        $roles = [];
        foreach (['admin', 'agent'] as $name) {
            $roles[$name] = Role::create(['name' => $name]);
        }
        $this->admin = User::create(['name' => 'Ad Min', 'email' => 'admin@example.test', 'role_id' => $roles['admin']->id]);
        $this->agentUser = User::create(['name' => 'Tracy Villarias', 'email' => 'tracy@example.test', 'role_id' => $roles['agent']->id]);
        DB::table('agents')->insert(['id' => 1, 'user_id' => $this->agentUser->id, 'first_name' => 'Tracy', 'last_name' => 'Villarias', 'status' => 'active']);
        DB::table('categories')->insert([['id' => 1, 'name' => 'For Sale'], ['id' => 2, 'name' => 'For Rent']]);
        DB::table('projects')->insert([
            ['id' => 86, 'name' => 'Horizon 101', 'slug' => 'horizon-101', 'complete_address' => 'Mango Avenue, Cebu City', 'views' => 4000],
            ['id' => 90, 'name' => 'Corona Del Mar', 'slug' => 'corona-del-mar', 'complete_address' => 'Pooc, Talisay City', 'views' => 100],
            ['id' => 91, 'name' => 'Grand Residences', 'slug' => 'grand-residences', 'complete_address' => 'Cebu City', 'views' => 50],
        ]);
        DB::table('properties')->insert([
            ['id' => 1, 'name' => 'Corona Del Mar Lot', 'address' => 'Pooc, Talisay City', 'project_id' => null, 'is_project' => false],
            ['id' => 2, 'name' => 'Horizon 101', 'address' => 'Mango Avenue, Cebu City', 'project_id' => 86, 'is_project' => true],
            ['id' => 3, 'name' => 'Grand Residences', 'address' => 'Cebu City', 'project_id' => 91, 'is_project' => true],
            ['id' => 4, 'name' => 'Condo', 'address' => 'Lahug, Cebu City', 'project_id' => null, 'is_project' => false],
        ]);
        $photo = json_encode(['https://cdn.example.test/a.jpg']);
        DB::table('listings')->insert([
            ['id' => 101, 'name' => 'Corona Del Mar Lot', 'slug' => 'corona-del-mar-lot', 'price' => 2900000, 'featured_photo' => $photo, 'category_id' => 1, 'agent_id' => 1, 'property_id' => 1],
            ['id' => 102, 'name' => 'Horizon 101 studio for rent', 'slug' => 'h101-studio', 'price' => 18000, 'featured_photo' => null, 'category_id' => 2, 'agent_id' => 1, 'property_id' => 2],
            ['id' => 103, 'name' => 'AMANI GRAND RESORT RESIDENCES studio', 'slug' => 'amani', 'price' => 4000000, 'featured_photo' => null, 'category_id' => 1, 'agent_id' => 1, 'property_id' => 3],
            ['id' => 104, 'name' => 'Condo near Horizon 101', 'slug' => 'near-h101', 'price' => 3000000, 'featured_photo' => null, 'category_id' => 1, 'agent_id' => 1, 'property_id' => 4],
        ]);
        DB::table('projects')->insert(['id' => 92, 'name' => 'Amani Grand Resort Residences', 'slug' => 'amani-grand', 'complete_address' => 'Mactan', 'views' => 10]);
    }

    public function test_agents_are_kept_out(): void
    {
        Sanctum::actingAs($this->agentUser);

        $this->getJson('/api/projects/tagging')->assertStatus(403);
        $this->postJson('/api/projects/tagging/101/tag', ['project_id' => 90])->assertStatus(403);
    }

    public function test_the_report_flags_the_untagged_and_the_mismatched_and_skips_near(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/projects/tagging')->assertOk()->json();

        $this->assertSame(4, $res['summary']['listings_checked']);
        $this->assertSame(2, $res['summary']['tagged_listings']);
        $this->assertSame(1, $res['summary']['not_tagged']);
        $this->assertSame(1, $res['summary']['mismatch']);
        $byId = collect($res['rows'])->keyBy('listing_id');
        $this->assertSame(['not_tagged', 'mismatch'], [$byId[101]['status'], $byId[103]['status']]);
        $this->assertSame('Corona Del Mar', $byId[101]['named_project']['name']);
        $this->assertNull($byId[101]['current_project']);
        $this->assertSame('https://cdn.example.test/a.jpg', $byId[101]['photo']);
        $this->assertSame('Tracy Villarias', $byId[101]['agent']['name']);
        $this->assertSame('Grand Residences', $byId[103]['current_project']['name']);
        $this->assertSame('Amani Grand Resort Residences', $byId[103]['named_project']['name']);
        $this->assertArrayNotHasKey(102, $byId->all(), 'tagged and agreeing');
        $this->assertArrayNotHasKey(104, $byId->all(), '"near" is a neighbour');
        $this->assertEqualsCanonicalizing(['Amani Grand Resort Residences', 'Corona Del Mar'], array_column($res['by_project'], 'name'));
    }

    public function test_one_click_tags_the_listing_and_writes_the_activity_line(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/projects/tagging/101/tag', ['project_id' => 90])
            ->assertOk()
            ->assertJsonPath('project.name', 'Corona Del Mar');

        $property = DB::table('properties')->where('id', 1)->first();
        $this->assertSame(90, (int) $property->project_id);
        $this->assertSame(1, (int) $property->is_project);
        $this->assertSame('Corona Del Mar Lot', $property->name, 'a real property name is kept');

        $audit = DB::table('audits')->where('auditable_id', 101)->where('event', 'project_tagged')->first();
        $this->assertNotNull($audit, 'the listing activity log gets a line');
        $this->assertSame('Project: none → Corona Del Mar', $audit->description);
        $this->assertSame('Ad Min', $audit->user_name, 'the admin who did it is on the line');

        $after = $this->getJson('/api/projects/tagging')->assertOk()->json();
        $this->assertSame(0, $after['summary']['not_tagged'], 'the fixed listing leaves the list at once');
        $this->assertSame(3, $after['summary']['tagged_listings']);
    }

    public function test_retagging_a_mirrored_property_name_follows_the_new_project(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/projects/tagging/103/tag', ['project_id' => 92])->assertOk();

        $property = DB::table('properties')->where('id', 3)->first();
        $this->assertSame(92, (int) $property->project_id);
        $this->assertSame('Amani Grand Resort Residences', $property->name, 'a name that only mirrored the old tag follows the new one');
    }

    public function test_a_missing_project_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/projects/tagging/101/tag', ['project_id' => 999])->assertStatus(422);
        $this->assertNull(DB::table('properties')->where('id', 1)->value('project_id'));
    }

    public function test_not_this_project_hides_the_pair(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/projects/tagging/101/dismiss', ['project_id' => 90])->assertOk();

        $this->assertSame(1, DB::table('project_tag_dismissals')->where('listing_id', 101)->where('project_id', 90)->where('user_id', $this->admin->id)->count());
        $after = $this->getJson('/api/projects/tagging')->assertOk()->json();
        $this->assertSame([], array_filter($after['rows'], fn ($r) => $r['listing_id'] === 101));
    }

    public function test_the_detector_names_the_project_for_the_listing_form(): void
    {
        Sanctum::actingAs($this->agentUser);

        $this->getJson('/api/projects/detect?text=2BR%20unit%20in%20Horizon%20101%20Mango%20Ave')->assertOk()->assertJsonPath('project.name', 'Horizon 101');
        $this->getJson('/api/projects/detect?text=Condo%20near%20Horizon%20101')->assertOk()->assertJsonPath('project', null);
        $this->getJson('/api/projects/detect?text=ab')->assertOk()->assertJsonPath('project', null);
        $this->assertSame('horizon 101 tower', ProjectTagAudit::normalize("Horizon 101 Tower's"));
    }
}
