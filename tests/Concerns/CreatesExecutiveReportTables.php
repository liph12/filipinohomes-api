<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every table the Executive Report reads, trimmed to the columns it uses,
 * plus builders for the records a test needs (users, agents, listings,
 * inquiries with their messages).
 *
 * Rows go in through DB::table, never the models: User / Agent / Listing are
 * audited, and a fixture that quietly writes `audits` rows would make every
 * agent look "active" (an audited edit is an activity signal).
 *
 * The report's own tables are created by running their real migrations, so
 * the test schema is the shipped one. Times are given in Manila and stored
 * in UTC, as production stores them.
 */
trait CreatesExecutiveReportTables
{
    protected const ADMIN = 1;

    protected const AGENT = 2;

    protected const CLIENT = 3;

    protected const EDITOR = 4;

    protected const SECRETARY = 5;

    protected function createExecutiveReportTables(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->default('x');
            $table->unsignedBigInteger('role_id')->nullable();
            $table->text('avatar')->nullable();
            $table->timestamp('active_at')->nullable();
            $table->timestamp('last_online_at')->nullable();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name')->default('t');
            $table->string('token', 64)->unique();
            $table->timestamps();
        });
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('source', 64)->nullable();
            $table->string('user_name')->nullable();
            $table->timestamps();
        });
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->text('avatar')->nullable();
            $table->string('status')->default('active');
            $table->string('region')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('team_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('agent_id');
            $table->boolean('is_leader')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('province_id')->nullable();
        });
        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('city_id');
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('prov_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('property_subtypes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('property_type_id');
        });
        Schema::create('property_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_subtype_id')->nullable();
            $table->decimal('floor_area', 11, 2)->default(0);
            $table->decimal('lot_area', 11, 2)->default(0);
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('property_attribute_id')->nullable();
            $table->string('status')->default('active');
            $table->date('status_change_date')->nullable();
            $table->text('geo_coordinates')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('code')->nullable();
            $table->decimal('price', 20, 2)->nullable();
            $table->text('featured_photo')->nullable();
            $table->string('visibility')->default('public');
            $table->string('verification_status')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('type_id');
            $table->string('source', 32)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('client_briefs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id')->nullable(); // null = an admin's own link
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 10);
            $table->string('name', 200);
            $table->timestamps();
        });
        Schema::create('buyer_forms', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('agent_id');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('buyer_form_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buyer_form_id');
            $table->unsignedBigInteger('user_id');
            $table->string('full_name');
            $table->timestamps();
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('agent_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('listing_id');
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        foreach ([
            '2026_11_11_100002_create_listing_sold_claims_table.php',
            '2026_11_11_100003_create_executive_report_analyses_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        DB::table('roles')->insert([
            ['id' => self::ADMIN, 'name' => 'admin'],
            ['id' => self::AGENT, 'name' => 'agent'],
            ['id' => self::CLIENT, 'name' => 'client'],
            ['id' => self::EDITOR, 'name' => 'editor'],
            ['id' => self::SECRETARY, 'name' => 'secretary'],
        ]);
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'For Sale'],
            ['id' => 2, 'name' => 'For Rent'],
            ['id' => 3, 'name' => 'Foreclosure'],
        ]);
        DB::table('property_types')->insert([
            ['id' => 1, 'name' => 'Condominium'],
            ['id' => 2, 'name' => 'House'],
            ['id' => 3, 'name' => 'Land'],
        ]);
        DB::table('property_subtypes')->insert([
            ['id' => 11, 'name' => 'Studio', 'property_type_id' => 1],
            ['id' => 21, 'name' => 'Single Detached', 'property_type_id' => 2],
            ['id' => 31, 'name' => 'Residential Lot', 'property_type_id' => 3],
        ]);

        // Cebu with a duplicate "Carmen" row; Samar's two province rows (82
        // "Southern Samar" folds onto 83 "Samar") each holding a Catbalogan.
        DB::table('provinces')->insert([
            ['id' => 1, 'name' => 'Cebu'],
            ['id' => 2, 'name' => 'Davao del Sur'],
            ['id' => 82, 'name' => 'Southern Samar'],
            ['id' => 83, 'name' => 'Samar'],
        ]);
        DB::table('cities')->insert([
            ['id' => 10, 'name' => 'Cebu City', 'province_id' => 1],
            ['id' => 11, 'name' => 'Mandaue City', 'province_id' => 1],
            ['id' => 12, 'name' => 'Carmen', 'province_id' => 1],
            ['id' => 13, 'name' => 'Carmen', 'province_id' => 1],
            ['id' => 20, 'name' => 'Davao City', 'province_id' => 2],
            ['id' => 30, 'name' => 'Catbalogan City', 'province_id' => 83],
            ['id' => 31, 'name' => 'Catbalogan City', 'province_id' => 82],
        ]);
        foreach ([10, 11, 12, 13, 20, 30, 31] as $cityId) {
            DB::table('barangays')->insert(['id' => $cityId * 10, 'name' => "Barangay {$cityId}", 'city_id' => $cityId]);
        }
    }

    /** A Manila wall-clock time as the UTC literal the database stores. */
    protected function utc(string $manila): string
    {
        return Carbon::parse($manila, 'Asia/Manila')->utc()->format('Y-m-d H:i:s');
    }

    protected function makeUser(int $role, array $attrs = []): int
    {
        static $n = 0;
        $n++;

        return DB::table('users')->insertGetId(array_merge([
            'name' => "Person {$n}",
            'email' => "p{$n}@example.com",
            'role_id' => $role,
            'created_at' => $this->utc('2026-01-01 09:00'),
            'updated_at' => $this->utc('2026-01-01 09:00'),
        ], $attrs));
    }

    /** An agent row (and its user) — returns [agentId, userId]. */
    protected function makeAgent(array $agent = [], array $user = [], int $role = self::AGENT): array
    {
        $userId = $this->makeUser($role, $user);
        $agentId = DB::table('agents')->insertGetId(array_merge([
            'user_id' => $userId,
            'first_name' => 'Agent',
            'last_name' => (string) $userId,
            'status' => 'active',
            'region' => 'cebu',
            'created_at' => $this->utc('2026-01-01 09:00'),
            'updated_at' => $this->utc('2026-01-01 09:00'),
        ], $agent));

        return [$agentId, $userId];
    }

    protected function makeTeam(string $name, array $agentIds, ?int $leader = null, string $status = 'active'): int
    {
        $teamId = DB::table('teams')->insertGetId(['name' => $name, 'status' => $status]);
        foreach ($agentIds as $agentId) {
            DB::table('team_agents')->insert([
                'team_id' => $teamId, 'agent_id' => $agentId, 'is_leader' => $agentId === $leader, 'status' => 'active',
            ]);
        }

        return $teamId;
    }

    /**
     * A listing with its property and attributes. Options: category (1 sale,
     * 2 rent), subtype (11 condo, 21 house, 31 land), city (barangay of that
     * city), project, price, floor, lot, status, deal (status_change_date),
     * added (Manila), visibility, flagged.
     */
    protected function makeListing(int $agentId, array $o = []): int
    {
        $attrId = DB::table('property_attributes')->insertGetId([
            'property_subtype_id' => $o['subtype'] ?? 11,
            'floor_area' => $o['floor'] ?? 50,
            'lot_area' => $o['lot'] ?? 0,
        ]);
        $propertyId = DB::table('properties')->insertGetId([
            'project_id' => $o['project'] ?? null,
            'address_id' => isset($o['city']) ? $o['city'] * 10 : 100,
            'property_attribute_id' => $attrId,
            'status' => $o['status'] ?? 'active',
            'status_change_date' => $o['deal'] ?? null,
            'geo_coordinates' => $o['pin'] ?? null,
        ]);

        static $n = 0;
        $n++;

        return DB::table('listings')->insertGetId([
            'name' => $o['name'] ?? "Listing {$n}",
            'slug' => "listing-{$n}",
            'code' => "FH-{$n}",
            'price' => $o['price'] ?? 5_000_000,
            'visibility' => $o['visibility'] ?? 'public',
            'verification_status' => ! empty($o['flagged']) ? 'flagged' : null,
            'category_id' => $o['category'] ?? 1,
            'property_id' => $propertyId,
            'agent_id' => $agentId,
            'created_at' => $this->utc($o['added'] ?? '2026-11-05 10:00'),
            'updated_at' => $this->utc($o['added'] ?? '2026-11-05 10:00'),
        ]);
    }

    /**
     * A listing inquiry: the client's chat, its conversation and messages.
     * $messages = [[userId, 'Manila time', 'body', status?], …].
     */
    protected function makeInquiry(int $listingId, int $clientId, string $received, array $conversation = [], array $messages = []): int
    {
        $chatId = DB::table('chats')->insertGetId([
            'type' => 'listing', 'type_id' => $listingId, 'user_id' => $clientId,
            'created_at' => $this->utc($received), 'updated_at' => $this->utc($received),
        ]);
        $conversationId = DB::table('conversations')->insertGetId(array_merge([
            'chat_id' => $chatId,
            'status' => 'accepted',
            'created_at' => $this->utc($received),
            'updated_at' => $this->utc($received),
        ], $conversation));
        foreach ($messages as $m) {
            DB::table('messages')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $m[0],
                'body' => $m[2] ?? 'Hello',
                'status' => $m[3] ?? 'active',
                'created_at' => $this->utc($m[1]),
                'updated_at' => $this->utc($m[1]),
            ]);
        }

        return $chatId;
    }

    /**
     * A client's direct message to an agent (to the agent's USER, as the chat
     * stores it): the chat, its auto-accepted conversation and messages.
     */
    protected function makeDirectMessage(int $agentUserId, int $clientId, string $received, array $messages = [], ?string $source = 'agent_profile'): int
    {
        $chatId = DB::table('chats')->insertGetId([
            'type' => 'agent', 'type_id' => $agentUserId, 'source' => $source, 'user_id' => $clientId,
            'created_at' => $this->utc($received), 'updated_at' => $this->utc($received),
        ]);
        $conversationId = DB::table('conversations')->insertGetId([
            'chat_id' => $chatId, 'status' => 'accepted', 'created_at' => $this->utc($received), 'updated_at' => $this->utc($received),
        ]);
        foreach ($messages as $m) {
            DB::table('messages')->insert([
                'conversation_id' => $conversationId, 'user_id' => $m[0], 'body' => $m[2] ?? 'Hello',
                'status' => $m[3] ?? 'active', 'created_at' => $this->utc($m[1]), 'updated_at' => $this->utc($m[1]),
            ]);
        }

        return $chatId;
    }

    /** A Buyer & Seller Link brief; a null agent is an admin's own link. */
    protected function makeBrief(?int $agentId, string $at, string $kind = 'buyer', string $name = 'Brief Client'): int
    {
        return DB::table('client_briefs')->insertGetId([
            'agent_id' => $agentId, 'kind' => $kind, 'name' => $name, 'created_at' => $this->utc($at), 'updated_at' => $this->utc($at),
        ]);
    }

    /** A registration on an agent's Buyer Form (the form is created on first use). */
    protected function makeRegistration(int $agentId, int $userId, string $at, string $title = 'Open House'): int
    {
        $formId = DB::table('buyer_forms')->where('agent_id', $agentId)->where('title', $title)->value('id')
            ?? DB::table('buyer_forms')->insertGetId(['title' => $title, 'agent_id' => $agentId]);

        return DB::table('buyer_form_registrations')->insertGetId([
            'buyer_form_id' => $formId, 'user_id' => $userId, 'full_name' => "Registrant {$userId}",
            'created_at' => $this->utc($at), 'updated_at' => $this->utc($at),
        ]);
    }

    protected function login(int $userId, string $manila): void
    {
        DB::table('login_logs')->insert(['user_id' => $userId, 'logged_in_at' => $this->utc($manila)]);
    }

    /**
     * The shared edge-case fixture, for "today" = Nov 10, 2026, 2 PM Manila and
     * the range Nov 1–10: a duplicate "Carmen", a listing filed under Southern
     * Samar's duplicate Catbalogan, an agent in two teams, a staff-owned
     * listing, a flagged and a private listing (out of ₱/sqm), one inquiry per
     * hanging bucket, a sold claim with a lead that came in after it, and the
     * other lead sources: an answered and an unanswered direct message (plus
     * one from a fellow agent, not a lead), a brief (plus one on an admin's
     * link) and an open-house registration (plus one by an agent).
     *
     * @return array<string, int> ids by name (a1…a6 agents, s1 the admin's agent row, u1/u3/u6 users,
     *                            c1…c4 clients, alpha/bravo/charlie teams, mantra, L1…L10 listings,
     *                            dm1/dm2 direct-message chats)
     */
    protected function seedExecutiveScenario(): array
    {
        $recent = $this->utc('2026-11-10 13:00');
        [$a1, $u1] = $this->makeAgent(['region' => 'cebu'], ['last_online_at' => $recent]);
        [$a2] = $this->makeAgent(['region' => 'cebu'], ['last_online_at' => $recent]);
        [$a3, $u3] = $this->makeAgent(['region' => 'davao'], ['last_online_at' => $recent]);
        [$a4, $u4] = $this->makeAgent(['region' => null], ['last_online_at' => $this->utc('2026-09-01 10:00')]);
        [$a5] = $this->makeAgent(['status' => 'inactive'], ['last_online_at' => $recent]);
        [$a6, $u6] = $this->makeAgent(['region' => 'bohol'], ['last_online_at' => $recent]); // on the roster, did nothing
        [$s1] = $this->makeAgent([], ['last_online_at' => $recent], self::ADMIN);     // staff with an agent row
        $id = compact('a1', 'a2', 'a3', 'a4', 'a5', 'a6', 's1', 'u1', 'u3', 'u6');

        $id['alpha'] = $this->makeTeam('Alpha', [$a1, $a2], leader: $a1);
        $id['bravo'] = $this->makeTeam('Bravo', [$a3]);
        $id['charlie'] = $this->makeTeam('Charlie', [$a2]); // a2 is credited to Alpha (lower id)

        $this->login($u1, '2026-11-03 09:00');
        $this->login($u3, '2026-11-04 09:00');
        $this->login($u4, '2026-09-01 09:00');

        $id['mantra'] = DB::table('projects')->insertGetId(['name' => 'Mantra', 'slug' => 'mantra', 'city_id' => 10, 'prov_id' => 1]);

        $L = fn (int $agent, array $o) => $this->makeListing($agent, $o);
        $id['L1'] = $L($a1, ['city' => 10, 'project' => $id['mantra'], 'added' => '2026-11-05 10:00', 'pin' => '{"lat": 10.3157, "lng": 123.8854}']); // 5M / 50 = 100k
        $id['L2'] = $L($a1, ['city' => 12, 'added' => '2026-10-01 10:00', 'status' => 'sold', 'deal' => '2026-11-06']); // a deal, added earlier
        $id['L3'] = $L($a2, ['city' => 13, 'subtype' => 21, 'added' => '2026-11-07 10:00']);                            // twin Carmen, house 100k
        $id['L4'] = $L($a3, ['city' => 20, 'subtype' => 31, 'price' => 2_000_000, 'floor' => 0, 'lot' => 200, 'added' => '2026-11-02 10:00']); // land 10k
        $id['L5'] = $L($a3, ['city' => 20, 'category' => 2, 'price' => 20_000, 'floor' => 40, 'added' => '2026-11-03 10:00', 'status' => 'rented', 'deal' => '2026-11-08']);
        $id['L6'] = $L($a4, ['city' => 31, 'subtype' => 21, 'added' => '2026-11-04 10:00']);                            // Southern Samar's Catbalogan
        $id['L7'] = $L($s1, ['city' => 10, 'added' => '2026-11-06 10:00']);                                              // staff-owned
        $id['L8'] = $L($a2, ['city' => 10, 'flagged' => true, 'added' => '2026-11-06 11:00']);                         // out of ₱/sqm
        $id['L9'] = $L($a1, ['city' => 10, 'added' => '2026-11-09 08:00']);                                              // "sold" but still active
        $id['L10'] = $L($a2, ['city' => 10, 'visibility' => 'private', 'added' => '2026-11-02 12:00']);                 // out of ₱/sqm

        [$c1, $c2, $c3, $c4] = [
            $this->makeUser(self::CLIENT, ['created_at' => $this->utc('2026-11-02 08:00')]),
            $this->makeUser(self::CLIENT, ['created_at' => $this->utc('2026-11-02 08:00')]),
            $this->makeUser(self::CLIENT, ['created_at' => $this->utc('2026-08-01 08:00')]),
            $this->makeUser(self::CLIENT, ['created_at' => $this->utc('2026-11-06 08:00')]), // never used it
        ];
        $this->login($c1, '2026-11-02 08:05');
        DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => $c2, 'token' => 'tok-c2']);
        $this->login($c3, '2026-11-06 08:05'); // a returning client
        $id += compact('c1', 'c2', 'c3', 'c4');

        // stalled: the agent replied, the client has been quiet since Nov 3.
        $this->makeInquiry($id['L1'], $c1, '2026-11-03 10:00',
            ['agent_user_id' => $u1, 'reviewed_at' => $this->utc('2026-11-03 11:00')],
            [[$c1, '2026-11-03 10:00'], [$u1, '2026-11-03 12:00']]);
        // no_reply: reached the agent Nov 8, nobody answered.
        $this->makeInquiry($id['L4'], $c2, '2026-11-08 09:00',
            ['agent_user_id' => $u3, 'reviewed_at' => $this->utc('2026-11-08 09:30')],
            [[$c2, '2026-11-08 09:00']]);
        // client_waiting: the client wrote last on Nov 8.
        $this->makeInquiry($id['L5'], $c3, '2026-11-07 09:00',
            ['agent_user_id' => $u3, 'reviewed_at' => $this->utc('2026-11-07 09:10')],
            [[$c3, '2026-11-07 09:00'], [$u3, '2026-11-07 10:00'], [$c3, '2026-11-08 10:00']]);
        // pending_review: still in moderation after a day.
        $this->makeInquiry($id['L3'], $c1, '2026-11-09 08:00', ['status' => 'pending'], [[$c1, '2026-11-09 08:00']]);
        // The agent says it's sold — a strong claim; the listing stays active.
        $id['claim_chat'] = $this->makeInquiry($id['L9'], $c2, '2026-11-09 13:00',
            ['agent_user_id' => $u1, 'reviewed_at' => $this->utc('2026-11-09 13:00')],
            [[$c2, '2026-11-09 13:00'], [$u1, '2026-11-09 13:30', 'Sorry po, sold na po ang unit.']]);
        $claimMessage = DB::table('messages')->where('body', 'like', 'Sorry po%')->value('id');
        DB::table('listing_sold_claims')->insert([
            'message_id' => $claimMessage, 'conversation_id' => DB::table('messages')->where('id', $claimMessage)->value('conversation_id'),
            'chat_id' => $id['claim_chat'], 'listing_id' => $id['L9'], 'speaker_user_id' => $u1, 'speaker_role' => 'agent',
            'client_user_id' => $c2, 'tier' => 'strong', 'phrase' => 'sold na', 'snippet' => 'Sorry po, sold na po ang unit.',
            'said_at' => $this->utc('2026-11-09 13:30'), 'matcher_version' => 1,
        ]);
        // A lead that arrived after the agent had said "sold".
        $this->makeInquiry($id['L9'], $c3, '2026-11-10 10:00',
            ['agent_user_id' => $u1, 'reviewed_at' => $this->utc('2026-11-10 10:00')],
            [[$c3, '2026-11-10 10:00']]);

        // Direct messages: a1 answered c3's; nobody answered c1's to a6 (who stays idle — the
        // client's message is the client's activity); a fellow agent's message is no lead.
        $id['dm1'] = $this->makeDirectMessage($u1, $c3, '2026-11-05 10:00', [[$c3, '2026-11-05 10:00'], [$u1, '2026-11-05 11:00']]);
        $id['dm2'] = $this->makeDirectMessage($u6, $c1, '2026-11-06 09:00', [[$c1, '2026-11-06 09:00']], 'agent_page');
        $this->makeDirectMessage($u1, $u3, '2026-11-07 09:00', [[$u3, '2026-11-07 09:00']]);
        // A brief on a1's link, and one on an admin's own link (no agent).
        $this->makeBrief($a1, '2026-11-07 12:00', 'buyer', 'Ana Buyer');
        $this->makeBrief(null, '2026-11-07 13:00');
        // A client registered at a3's open house; an agent's registration is no lead.
        $this->makeRegistration($a3, $c2, '2026-11-08 10:00');
        $this->makeRegistration($a3, $u4, '2026-11-08 11:00');

        return $id;
    }
}
