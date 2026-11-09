<?php

namespace Tests\Feature;

use App\Mail\BriefReceivedMailer;
use App\Models\Agent;
use App\Models\BriefLink;
use App\Models\ClientBrief;
use App\Models\Role;
use App\Models\User;
use App\Support\Briefs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Buyers & Sellers Link: one permanent link per agent, two public briefs,
 * every answer narrowed to known option keys, and the briefs scoped to the
 * agent they came through.
 */
class BriefLinkTest extends TestCase
{
    private User $agentUser;

    private Agent $agent;

    private User $otherAgentUser;

    private Agent $otherAgent;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.guest_api_secret' => 'guest-secret']);
        Mail::fake();

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('avatar')->nullable();
            $t->string('mobile_no')->nullable();
            $t->foreignId('role_id')->nullable();
            $t->timestamps();
        });
        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->text('avatar')->nullable();
            $t->string('mobile_no')->nullable();
            $t->string('whats_app_no')->nullable();
            $t->string('lr_email')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('brief_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->foreignId('agent_id')->nullable()->unique();
            $t->string('slug', 80)->unique();
            $t->string('code', 12)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('client_briefs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brief_link_id');
            $t->foreignId('agent_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('kind', 10);
            $t->string('name', 200);
            $t->string('phone_code', 8)->default('+63');
            $t->string('phone', 32);
            $t->string('email', 320);
            $t->string('contact_time', 20)->nullable();
            $t->string('budget', 20)->nullable();
            $t->text('message')->nullable();
            $t->json('profile');
            $t->string('ip_address', 64)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->timestamps();
        });

        Model::unguard();
        $roles = [];
        foreach (['admin', 'agent', 'client'] as $name) {
            $roles[$name] = Role::create(['name' => $name]);
        }
        $this->agentUser = User::create(['name' => 'Neil Allan Mary', 'email' => 'neil@example.test', 'role_id' => $roles['agent']->id]);
        $this->agent = Agent::create([
            'user_id' => $this->agentUser->id,
            'first_name' => 'NEIL ALLAN',
            'last_name' => 'MARY',
            'mobile_no' => '0917 123 4567',
            'status' => 'active',
        ]);
        $this->otherAgentUser = User::create(['name' => 'Other Agent', 'email' => 'other@example.test', 'role_id' => $roles['agent']->id]);
        $this->otherAgent = Agent::create(['user_id' => $this->otherAgentUser->id, 'first_name' => 'Other', 'last_name' => 'Agent', 'status' => 'active']);
        $this->client = User::create(['name' => 'Juan Dela Cruz', 'email' => 'juan@example.test', 'role_id' => $roles['client']->id]);
        $this->admin = User::create(['name' => 'IT Admin', 'email' => 'admin@example.test', 'role_id' => $roles['admin']->id]);
        Model::reguard();
    }

    private function guest(): static
    {
        $payload = json_encode(['exp' => time() + 600]);

        return $this->withHeader('X-Guest-Token', base64_encode($payload).'.'.hash_hmac('sha256', $payload, 'guest-secret'));
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this->guest();
    }

    private function link(Agent $agent, array $extra = []): BriefLink
    {
        return BriefLink::create(array_merge(['user_id' => $agent->user_id, 'agent_id' => $agent->id, 'slug' => 'agent-'.$agent->id, 'code' => 'c'.str_pad((string) $agent->id, 7, 'x')], $extra));
    }

    private function buyerPayload(array $over = []): array
    {
        return array_replace_recursive([
            'kind' => 'buyer',
            'name' => 'Juan Dela Cruz',
            'phone_code' => '+63',
            'phone' => '0917 555 1234',
            'email' => 'Juan@Example.test',
            'contact_time' => 'evening',
            'budget' => '3m_5m',
            'message' => 'Near my work in IT Park please.',
            'profile' => [
                'residence' => 'ph',
                'contact_channel' => 'viber',
                'buying_for' => 'family',
                'buy_timeline' => '1_3m',
                'property_types' => ['condo', 'house_lot', 'bogus'],
                'areas' => ['cebu'],
                'areas_cities' => ['Cebu City, Cebu', '  Mandaue City, Cebu ', 'Cebu City, Cebu'],
                'must_haves' => ['school', 'flood_free'],
                'payment' => 'cash',
                'loan_status' => 'approved',
                'readiness' => 'reserve',
                'profession' => 'Nurse',
                'hacker' => 'drop table',
            ],
        ], $over);
    }

    // ── definitions ───────────────────────────────────────────────────────

    public function test_the_definitions_are_public_and_carry_both_briefs(): void
    {
        $res = $this->getJson('/api/briefs/questions')->assertOk();
        $this->assertCount(4, $res->json('buyer.steps'));
        $this->assertCount(4, $res->json('seller.steps'));
        $this->assertSame('Under ₱1M', $res->json('budget.0.label'));
        $this->assertSame('Pag-IBIG loan', collect($res->json('buyer.questions.payment.options'))->firstWhere('value', 'pagibig')['label']);
        $this->assertSame(['key' => 'payment', 'in' => ['bank_loan', 'pagibig']], $res->json('buyer.questions.loan_status.show_when'));
    }

    // ── the agent's link ──────────────────────────────────────────────────

    public function test_an_agent_gets_one_link_named_after_them_and_keeps_it(): void
    {
        $first = $this->as($this->agentUser)->postJson('/api/agent/brief-link')->assertOk();
        $this->assertSame('neil-allan-mary', $first->json('data.slug'));
        $this->assertTrue($first->json('data.is_active'), 'live from the start, not null until a reload');
        $this->assertMatchesRegularExpression('/^[23456789abcdefghjkmnpqrstuvwxyz]{8}$/', $first->json('data.code'));

        $again = $this->as($this->agentUser)->postJson('/api/agent/brief-link')->assertOk();
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, BriefLink::count());
    }

    public function test_a_second_agent_with_the_same_name_gets_a_numbered_address(): void
    {
        $this->link($this->otherAgent, ['slug' => 'neil-allan-mary']);

        $this->as($this->agentUser)->postJson('/api/agent/brief-link')
            ->assertOk()
            ->assertJsonPath('data.slug', 'neil-allan-mary-2');
    }

    public function test_a_client_has_no_link(): void
    {
        $this->as($this->client)->postJson('/api/agent/brief-link')->assertForbidden();
    }

    // ── the public page ───────────────────────────────────────────────────

    public function test_the_public_page_data_loads_with_no_token_and_shows_the_agent(): void
    {
        $link = $this->link($this->agent, ['slug' => 'neil-allan-mary']);

        $this->getJson('/api/brief-links/neil-allan-mary')
            ->assertOk()
            ->assertJsonPath('data.code', $link->code)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.agent.name', 'Neil Allan Mary')
            ->assertJsonPath('data.agent.first_name', 'Neil')
            ->assertJsonPath('data.agent.initials', 'NM')
            ->assertJsonPath('data.agent.phone', '639171234567')
            ->assertJsonPath('data.agent.whatsapp', '639171234567');

        $this->getJson('/api/brief-links/nobody-here')->assertNotFound();
    }

    public function test_a_blocked_agents_link_is_gone(): void
    {
        $this->link($this->agent, ['slug' => 'neil-allan-mary']);
        $this->agent->update(['status' => 'resigned']);

        $this->getJson('/api/brief-links/neil-allan-mary')->assertNotFound();
    }

    // ── a buyer's brief ───────────────────────────────────────────────────

    public function test_a_buyer_brief_is_stored_clean_graded_and_emailed_to_the_agent(): void
    {
        $link = $this->link($this->agent);

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload())->assertCreated();

        $brief = ClientBrief::firstOrFail();
        $this->assertSame('buyer', $brief->kind);
        $this->assertSame($this->agent->id, $brief->agent_id);
        $this->assertSame('juan@example.test', $brief->email);
        $this->assertSame('3m_5m', $brief->budget);
        $p = $brief->profile;
        $this->assertSame(['condo', 'house_lot'], $p['property_types'], 'unknown option dropped');
        $this->assertSame(['Cebu City, Cebu', 'Mandaue City, Cebu'], $p['areas_cities'], 'trimmed and de-duplicated');
        $this->assertArrayNotHasKey('loan_status', $p, 'a pre-approval only applies to a loan buyer');
        $this->assertArrayNotHasKey('hacker', $p);
        $this->assertSame('Nurse', $p['profession']);
        $this->assertSame('priority', Briefs::grade($brief->budget, $p));

        Mail::assertSent(BriefReceivedMailer::class, function (BriefReceivedMailer $mail) use ($brief) {
            $mail->assertHasTo('neil@example.test');

            return $mail->brief->is($brief) && $mail->envelope()->subject === 'New buyer brief: Juan Dela Cruz';
        });
    }

    public function test_the_email_reads_every_answer_in_the_clients_steps(): void
    {
        $link = $this->link($this->agent);
        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload())->assertCreated();

        $html = (new BriefReceivedMailer(ClientBrief::firstOrFail(), $link))->render();
        foreach (['Neil Allan Mary', 'Juan Dela Cruz', '+63 0917 555 1234', '₱3M – 5M', 'Condominium, House and Lot', 'Cebu, Cebu City, Cebu, Mandaue City, Cebu', 'Near schools, Flood-free area', 'Priority', 'Near my work in IT Park please.', 'wa.me/639175551234', '/agent/buyers-link?view=buyers&amp;brief='] as $needle) {
            $this->assertStringContainsString($needle, $html, "email shows: $needle");
        }
    }

    public function test_the_honeypot_swallows_bots(): void
    {
        $link = $this->link($this->agent);

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload(['website' => 'http://spam']))->assertOk();

        $this->assertSame(0, ClientBrief::count());
        Mail::assertNothingSent();
    }

    public function test_a_required_answer_or_the_budget_missing_is_a_422_that_names_it(): void
    {
        $link = $this->link($this->agent);

        $payload = $this->buyerPayload();
        unset($payload['profile']['buying_for']);
        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Please answer "Who are you buying for?".');

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload(['budget' => 'millions']))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Please choose your budget.');

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload(['phone' => 'abc']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);

        $this->assertSame(0, ClientBrief::count());
    }

    public function test_a_paused_link_or_a_bad_code_takes_nothing(): void
    {
        $link = $this->link($this->agent, ['is_active' => false]);

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload())->assertNotFound();
        $this->guest()->postJson('/api/brief-links/zzzzzzzz/briefs', $this->buyerPayload())->assertNotFound();
        $this->flushHeaders()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload())->assertUnauthorized(); // no guest token
    }

    // ── a seller's brief ──────────────────────────────────────────────────

    public function test_a_seller_brief_keeps_only_the_questions_that_apply(): void
    {
        $link = $this->link($this->agent);

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", [
            'kind' => 'seller',
            'name' => 'Maria Santos',
            'phone_code' => '+63',
            'phone' => '09181234567',
            'email' => 'maria@example.test',
            'profile' => [
                'relation' => 'owner',
                'property_type' => 'lot',
                'area' => 'cebu',
                'city' => 'Liloan, Cebu',
                'bedrooms' => '3',          // a lot has no rooms
                'furnishing' => 'furnished', // nor furniture
                'lot_area_sqm' => '1,250',
                'asking_price' => '₱2,500,000',
                'completion' => 'preselling',
                'ownership' => 'fully_paid',
                'paid_percent' => '80_plus', // only while still paying
                'turnover' => '1_2y',
                'occupancy' => 'tenanted',   // only for a built property
                'tenancy_ends' => '3_6m',
                'title' => 'clean',
                'sell_timeline' => 'asap',
            ],
        ])->assertCreated();

        $p = ClientBrief::firstOrFail()->profile;
        $this->assertSame('Liloan, Cebu', $p['city']);
        $this->assertSame('1250', $p['lot_area_sqm']);
        $this->assertSame('2500000', $p['asking_price']);
        $this->assertSame('1_2y', $p['turnover']);
        foreach (['bedrooms', 'furnishing', 'paid_percent', 'occupancy', 'tenancy_ends'] as $gone) {
            $this->assertArrayNotHasKey($gone, $p, "$gone does not apply");
        }
        $this->assertNull(ClientBrief::first()->budget);

        Mail::assertSent(BriefReceivedMailer::class, fn ($m) => $m->envelope()->subject === 'New seller brief: Maria Santos');
    }

    public function test_a_seller_must_say_where_the_property_is(): void
    {
        $link = $this->link($this->agent);

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", [
            'kind' => 'seller',
            'name' => 'Maria Santos',
            'phone_code' => '+63',
            'phone' => '09181234567',
            'email' => 'maria@example.test',
            'profile' => ['property_type' => 'condo', 'completion' => 'ready', 'sell_timeline' => 'asap'],
        ])->assertUnprocessable()->assertJsonPath('message', 'Please tell us where the property is.');
    }

    // ── reading briefs ────────────────────────────────────────────────────

    public function test_an_agent_reads_only_their_own_briefs_and_an_admin_reads_all(): void
    {
        $mine = $this->link($this->agent);
        $theirs = $this->link($this->otherAgent);
        $this->guest()->postJson("/api/brief-links/{$mine->code}/briefs", $this->buyerPayload())->assertCreated();
        $this->guest()->postJson("/api/brief-links/{$mine->code}/briefs", $this->buyerPayload(['budget' => 'under_1m', 'profile' => ['buy_timeline' => 'exploring', 'readiness' => 'exploring']]))->assertCreated();
        $this->guest()->postJson("/api/brief-links/{$theirs->code}/briefs", $this->buyerPayload(['name' => 'Someone Else']))->assertCreated();

        $list = $this->as($this->agentUser)->getJson('/api/agent/briefs')->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertSame(['info', 'priority'], array_column($list->json('data'), 'grade'), 'newest first, graded');

        $this->as($this->agentUser)->getJson('/api/agent/briefs?kind=seller')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->otherAgentUser)->getJson('/api/agent/briefs')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->client)->getJson('/api/agent/briefs')->assertForbidden();

        $all = $this->as($this->admin)->getJson('/api/admin/briefs')->assertOk();
        $this->assertCount(3, $all->json('data'));
        $this->assertSame('Other Agent', $all->json('data.0.agent.full_name'));
        $this->assertSame($this->otherAgentUser->id, $all->json('data.0.agent.id'), 'the owner key is the account');
        $this->assertFalse($all->json('data.0.agent.is_admin'));
        $this->as($this->agentUser)->getJson('/api/admin/briefs')->assertForbidden();
    }

    // ── an admin's own link ───────────────────────────────────────────────

    public function test_an_admin_without_an_agent_row_has_a_link_and_gets_its_briefs(): void
    {
        $res = $this->as($this->admin)->postJson('/api/agent/brief-link')->assertOk();
        $this->assertSame('it-admin', $res->json('data.slug'), 'named after the account when there is no agent row');
        $link = BriefLink::findOrFail($res->json('data.id'));
        $this->assertSame($this->admin->id, $link->user_id);
        $this->assertNull($link->agent_id);

        $this->getJson('/api/brief-links/it-admin')
            ->assertOk()
            ->assertJsonPath('data.agent.name', 'It Admin')
            ->assertJsonPath('data.agent.first_name', 'It')
            ->assertJsonPath('data.agent.initials', 'IA');

        $this->guest()->postJson("/api/brief-links/{$link->code}/briefs", $this->buyerPayload())->assertCreated();
        $brief = ClientBrief::firstOrFail();
        $this->assertSame($this->admin->id, $brief->user_id);
        $this->assertNull($brief->agent_id);

        Mail::assertSent(BriefReceivedMailer::class, function (BriefReceivedMailer $mail) {
            $mail->assertHasTo('admin@example.test');

            return str_contains($mail->render(), '/admin/buyers-link?view=buyers&amp;brief=');
        });

        $mine = $this->as($this->admin)->getJson('/api/agent/briefs')->assertOk();
        $this->assertCount(1, $mine->json('data'));

        $all = $this->as($this->admin)->getJson('/api/admin/briefs')->assertOk();
        $this->assertSame('It Admin', $all->json('data.0.agent.full_name'));
        $this->assertSame($this->admin->id, $all->json('data.0.agent.id'));
        $this->assertTrue($all->json('data.0.agent.is_admin'));
    }

    public function test_an_admins_link_stays_up_whatever_their_agent_row_says(): void
    {
        Model::unguard();
        Agent::create(['user_id' => $this->admin->id, 'first_name' => 'IT', 'last_name' => 'ADMIN', 'status' => 'deactivated']);
        Model::reguard();

        $res = $this->as($this->admin)->postJson('/api/agent/brief-link')->assertOk();
        $this->assertSame('it-admin', $res->json('data.slug'));
        $this->assertNotNull(BriefLink::findOrFail($res->json('data.id'))->agent_id, 'the agent row is still the owner\'s face');

        $this->getJson('/api/brief-links/it-admin')->assertOk()->assertJsonPath('data.agent.name', 'It Admin');
    }

    public function test_an_agents_link_made_before_links_had_owners_is_claimed(): void
    {
        $old = $this->link($this->agent, ['user_id' => null]);
        $this->guest()->postJson("/api/brief-links/{$old->code}/briefs", $this->buyerPayload())->assertCreated();
        ClientBrief::query()->update(['user_id' => null]);

        $res = $this->as($this->agentUser)->postJson('/api/agent/brief-link')->assertOk();
        $this->assertSame($old->id, $res->json('data.id'), 'the same link, not a new one');
        $this->assertSame($this->agentUser->id, $old->fresh()->user_id);
        $this->assertSame($this->agentUser->id, ClientBrief::firstOrFail()->user_id);
        $this->assertCount(1, $this->as($this->agentUser)->getJson('/api/agent/briefs')->json('data'));
    }

    // ── the grade and the numbers ─────────────────────────────────────────

    public function test_the_grade_follows_budget_timing_funds_and_readiness(): void
    {
        $g = fn (string $budget, array $p) => Briefs::grade($budget, $p);
        $this->assertSame('priority', $g('3m_5m', ['buy_timeline' => 'asap', 'payment' => 'pagibig', 'loan_status' => 'in_progress', 'readiness' => 'serious']));
        $this->assertSame('qualified', $g('3m_5m', ['buy_timeline' => 'asap', 'payment' => 'pagibig', 'loan_status' => 'not_yet', 'readiness' => 'serious']), 'no funds yet, so not priority');
        $this->assertSame('qualified', $g('1m_2m', ['buy_timeline' => '6_12m', 'payment' => 'cash', 'readiness' => 'info']));
        $this->assertSame('nurture', $g('under_1m', ['buy_timeline' => '1_3m', 'payment' => 'cash', 'readiness' => 'reserve']));
        $this->assertSame('info', $g('8m_15m', ['buy_timeline' => 'exploring', 'payment' => 'cash', 'readiness' => 'exploring']));
    }

    public function test_phone_digits_handle_philippine_numbers(): void
    {
        $this->assertSame('639171234567', Briefs::phoneDigits('+63', '0917 123 4567'));
        $this->assertSame('639171234567', Briefs::phoneDigits('+63', '9171234567'));
        $this->assertSame('639171234567', Briefs::phoneDigits('+63', '+63 917 123 4567'));
        $this->assertSame('639171234567', Briefs::phoneDigits('+63', '63 917 123 4567'));
        $this->assertSame('971501234567', Briefs::phoneDigits('+971', '050 123 4567'));
        $this->assertSame('', Briefs::phoneDigits('+63', ''));
    }
}
