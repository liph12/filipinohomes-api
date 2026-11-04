<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\BuyerForm;
use App\Models\BuyerFormRegistration;
use App\Models\Project;
use App\Models\PropertyType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Buyer Form (Open House): an agent creates a form, shares its public link,
 * logged-in clients register, and the agent (or an admin) reads the list.
 *
 * Covers the fences the dashboard relies on: only an agent can create, an
 * agent sees only their own forms while an admin sees all, the public page
 * loads with no token at all (the Next page fetches it during SSR without
 * one), a client registers once no matter how many times they submit, and
 * another agent can neither read the registrations nor delete the form.
 */
class BuyerFormTest extends TestCase
{
    private User $agentUser;

    private Agent $agent;

    private User $otherAgentUser;

    private Agent $otherAgent;

    private User $client;

    private User $admin;

    private PropertyType $condo;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.guest_api_secret' => 'guest-secret']);

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('avatar')->nullable();
            $t->string('mobile_no')->nullable();
            $t->foreignId('role_id')->nullable();
            $t->timestamps();
        });
        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('middle_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('avatar')->nullable();
            $t->string('mobile_no')->nullable();
            $t->string('whatsapp_no')->nullable();
            $t->text('socials')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('property_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        // The Project model's saving/creating hooks also write these.
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('street')->nullable();
            $t->string('complete_address')->nullable();
            $t->string('mapaddress')->nullable();
            $t->text('featured_photo')->nullable();
            $t->text('photos_url')->nullable();
            $t->integer('views')->nullable();
            $t->string('added_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('buyer_forms', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('location')->nullable();
            $t->foreignId('property_type_id')->nullable();
            $t->integer('project_id')->nullable();
            $t->foreignId('agent_id');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('buyer_form_registrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('buyer_form_id');
            $t->foreignId('user_id');
            $t->string('full_name');
            $t->string('email');
            $t->string('home_address')->nullable();
            $t->timestamps();
            $t->unique(['buyer_form_id', 'user_id']);
        });

        Model::unguard();

        $roles = [];
        foreach (['admin', 'agent', 'client'] as $name) {
            $roles[$name] = Role::create(['name' => $name]);
        }

        $this->agentUser = User::create(['name' => 'Neil Allan Mary', 'email' => 'neil@example.test', 'role_id' => $roles['agent']->id]);
        $this->agent = Agent::create(['user_id' => $this->agentUser->id, 'first_name' => 'Neil Allan', 'last_name' => 'Mary', 'status' => 'active']);

        $this->otherAgentUser = User::create(['name' => 'Other Agent', 'email' => 'other@example.test', 'role_id' => $roles['agent']->id]);
        $this->otherAgent = Agent::create(['user_id' => $this->otherAgentUser->id, 'first_name' => 'Other', 'last_name' => 'Agent', 'status' => 'active']);

        $this->client = User::create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.test', 'role_id' => $roles['client']->id]);
        $this->admin = User::create(['name' => 'IT Admin', 'email' => 'admin@example.test', 'role_id' => $roles['admin']->id]);

        $this->condo = PropertyType::create(['name' => 'Condominium']);
        $this->project = Project::create([
            'name' => 'Mivesa Garden Residences',
            'complete_address' => 'Lahug, Cebu City',
            'featured_photo' => ['https://s3.test/mivesa.jpg'],
        ]);

        Model::reguard();
    }

    /** The site-wide guest token every browser request carries; signed by hand here. */
    private function guest(): static
    {
        $payload = json_encode(['exp' => time() + 600]);

        return $this->withHeader(
            'X-Guest-Token',
            base64_encode($payload).'.'.hash_hmac('sha256', $payload, 'guest-secret'),
        );
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this->guest();
    }

    private function form(Agent $agent, array $extra = []): BuyerForm
    {
        return BuyerForm::create(array_merge([
            'title' => 'Looking for Studio near St. Luke\'s',
            'location' => 'Quezon City',
            'property_type_id' => $this->condo->id,
            'agent_id' => $agent->id,
        ], $extra));
    }

    // ── create ────────────────────────────────────────────────────────────

    public function test_an_agent_creates_a_form_and_gets_a_share_slug(): void
    {
        $res = $this->as($this->agentUser)->postJson('/api/buyer-forms', [
            'title' => 'Looking for a 2BR Condo in Cebu City',
            'description' => 'Budget up to 6M, near IT Park.',
            'location' => 'Cebu City, Cebu',
            'property_type_id' => $this->condo->id,
            'project_id' => $this->project->id,
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.title', 'Looking for a 2BR Condo in Cebu City')
            ->assertJsonPath('data.category.name', 'Condominium')
            ->assertJsonPath('data.project.name', 'Mivesa Garden Residences')
            ->assertJsonPath('data.project.image', 'https://s3.test/mivesa.jpg')
            ->assertJsonPath('data.agent.full_name', 'Neil Allan Mary');

        $slug = $res->json('data.slug');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $slug);
        $this->assertSame($this->agent->id, BuyerForm::where('slug', $slug)->value('agent_id'));
    }

    public function test_project_is_optional_so_a_general_search_form_works(): void
    {
        $this->as($this->agentUser)->postJson('/api/buyer-forms', ['title' => 'Looking for Residential Lot'])
            ->assertCreated()
            ->assertJsonPath('data.project', null)
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.location', null);
    }

    public function test_title_is_required_and_ids_must_exist(): void
    {
        $this->as($this->agentUser)->postJson('/api/buyer-forms', ['description' => 'no title'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

        $this->as($this->agentUser)->postJson('/api/buyer-forms', [
            'title' => 'x',
            'project_id' => 999,
            'property_type_id' => 999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['project_id', 'property_type_id']);
    }

    public function test_a_client_or_an_admin_without_an_agent_row_cannot_create(): void
    {
        $this->as($this->client)->postJson('/api/buyer-forms', ['title' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only agents can create buyer forms');

        $this->as($this->admin)->postJson('/api/buyer-forms', ['title' => 'x'])
            ->assertForbidden();
    }

    public function test_a_guest_cannot_create(): void
    {
        $this->guest()->postJson('/api/buyer-forms', ['title' => 'x'])->assertUnauthorized();
    }

    // ── list ──────────────────────────────────────────────────────────────

    public function test_an_agent_lists_only_their_own_forms_and_an_admin_lists_all(): void
    {
        $mine = $this->form($this->agent);
        $theirs = $this->form($this->otherAgent, ['title' => 'Theirs']);
        BuyerFormRegistration::create([
            'buyer_form_id' => $mine->id,
            'user_id' => $this->client->id,
            'full_name' => 'Juan',
            'email' => 'juan@example.test',
        ]);

        $this->as($this->agentUser)->getJson('/api/buyer-forms?per_page=1000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.registrations_count', 1);

        $this->as($this->otherAgentUser)->getJson('/api/buyer-forms?per_page=1000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs->id);

        $this->as($this->admin)->getJson('/api/buyer-forms?per_page=1000')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── public page ───────────────────────────────────────────────────────

    public function test_the_public_page_loads_by_slug_with_no_token_at_all(): void
    {
        $form = $this->form($this->agent, ['project_id' => $this->project->id]);

        $this->getJson("/api/buyer-forms/{$form->slug}")
            ->assertOk()
            ->assertJsonPath('data.id', $form->id)
            ->assertJsonPath('data.title', 'Looking for Studio near St. Luke\'s')
            ->assertJsonPath('data.category.name', 'Condominium')
            ->assertJsonPath('data.project.image', 'https://s3.test/mivesa.jpg')
            ->assertJsonPath('data.agent.full_name', 'Neil Allan Mary');

        $this->getJson('/api/buyer-forms/nope00000000')->assertNotFound();
    }

    // ── register ──────────────────────────────────────────────────────────

    public function test_a_logged_in_client_registers_once_however_many_times_they_submit(): void
    {
        $form = $this->form($this->agent);

        $first = $this->as($this->client)->postJson("/api/buyer-forms/{$form->slug}/register", [
            'home_address' => 'Mandaue City',
        ]);
        $first->assertCreated()
            ->assertJsonPath('data.full_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.email', 'juan@example.test')
            ->assertJsonPath('data.home_address', 'Mandaue City');

        $again = $this->as($this->client)->postJson("/api/buyer-forms/{$form->slug}/register", [
            'home_address' => 'Somewhere else',
        ]);
        $again->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, BuyerFormRegistration::where('buyer_form_id', $form->id)->count());
        $this->assertSame('Mandaue City', BuyerFormRegistration::first()->home_address);
    }

    public function test_a_guest_must_log_in_to_register(): void
    {
        $form = $this->form($this->agent);

        $this->guest()->postJson("/api/buyer-forms/{$form->slug}/register", [])->assertUnauthorized();
    }

    public function test_an_over_long_address_is_a_422_not_a_500(): void
    {
        $form = $this->form($this->agent);

        $this->as($this->client)->postJson("/api/buyer-forms/{$form->slug}/register", [
            'home_address' => str_repeat('a', 256),
        ])->assertUnprocessable()->assertJsonValidationErrors(['home_address']);
    }

    // ── registrations ─────────────────────────────────────────────────────

    public function test_only_the_owner_and_admins_read_the_registrations(): void
    {
        $form = $this->form($this->agent);
        BuyerFormRegistration::create([
            'buyer_form_id' => $form->id,
            'user_id' => $this->client->id,
            'full_name' => 'Juan Dela Cruz',
            'email' => 'juan@example.test',
            'home_address' => 'Mandaue City',
        ]);

        $this->as($this->agentUser)->getJson("/api/buyer-forms/{$form->id}/registrations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Juan Dela Cruz');

        $this->as($this->admin)->getJson("/api/buyer-forms/{$form->id}/registrations")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->as($this->otherAgentUser)->getJson("/api/buyer-forms/{$form->id}/registrations")
            ->assertForbidden();

        $this->as($this->client)->getJson("/api/buyer-forms/{$form->id}/registrations")
            ->assertForbidden();
    }

    // ── delete ────────────────────────────────────────────────────────────

    public function test_only_the_owner_or_an_admin_deletes_and_the_public_link_dies_with_it(): void
    {
        $form = $this->form($this->agent);

        $this->as($this->otherAgentUser)->deleteJson("/api/buyer-forms/{$form->id}")->assertForbidden();
        $this->assertNull($form->fresh()->deleted_at);

        $this->as($this->agentUser)->deleteJson("/api/buyer-forms/{$form->id}")->assertOk();
        $this->assertNotNull(BuyerForm::withTrashed()->find($form->id)->deleted_at);

        $this->as($this->agentUser)->getJson('/api/buyer-forms?per_page=1000')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/buyer-forms/{$form->slug}")->assertNotFound();

        $other = $this->form($this->otherAgent);
        $this->as($this->admin)->deleteJson("/api/buyer-forms/{$other->id}")->assertOk();
    }
}
