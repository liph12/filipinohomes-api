<?php

namespace Tests\Feature;

use App\Http\Controllers\ListingController;
use App\Models\Role;
use App\Models\User;
use App\Services\Listing\ListingHeatmapService;
use App\Support\IslandMap;
use App\Support\ProvinceCanonicalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * GET /listings/insights/heatmap — the counts the choropleth shades by.
 *
 * The rules worth a test are the ones that are invisible when they break:
 * a province with no listings must still be ON the map (as a zero, not as a
 * hole), the duplicate Samar rows must land on ONE area, "new today" must mean
 * the Manila calendar day and not the UTC one, and a team leader must not see
 * the whole platform. Everything else the admin would notice immediately.
 *
 * Builds its own minimal tables — the full migration suite is MySQL-only, and
 * the heatmap query is deliberately dialect-neutral so it can run here.
 */
class ListingHeatmapTest extends TestCase
{
    /** Asia/Manila start of the current calendar day — the service's "today". */
    private Carbon $manilaMidnight;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->manilaMidnight = Carbon::now('Asia/Manila')->startOfDay();

        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->default('');
        });
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('province_id');
            $table->tinyInteger('type')->default(1);
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
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('prov_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->string('status')->default('active');
            $table->text('geo_coordinates')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        // No `geom` column: sqlite has no spatial type, and the counts service
        // only ever reads the id columns. Geometry is the other endpoint's job.
        // The PSGC columns are here because the real table has them after the
        // barangay import — nothing on this path reads them, and a fixture that
        // quietly drifts from the shipped schema is how the next person is
        // misled about what a row looks like.
        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->string('level');
            $table->string('name');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->string('psgc_code', 16)->nullable();
            $table->string('parent_psgc', 16)->nullable();
            $table->string('link_how', 16)->nullable();
        });
        // The 403 path resolves team leadership before it can answer.
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('active');
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

        $this->seedGeography();
        $this->seedListings();
    }

    /**
     * Province rows chosen for what they prove: Cebu (ordinary), the Samar pair
     * 82/83 (one real place, two rows), Siquijor (a row with no cities at all —
     * the zero-seed case), and the Dinagat Islands / Surigao del Norte pair.
     *
     * That last pair is here because it is the live shape of the hazard
     * {@see ProvinceCanonicalizer} documents: row 31 exists, 'dinagat islands'
     * is an ALIAS SOURCE, so 31 folds onto 73 and never reaches the map. Both
     * rows have to be in the fixture for the fold to be visible — seeding 31
     * alone makes it look like Dinagat survives, which in production it does
     * not. The mirror guard below pins the fixture to that reality.
     */
    private function seedGeography(): void
    {
        DB::table('provinces')->insert([
            ['id' => 25, 'name' => 'Cebu', 'code' => 'CEB'],
            ['id' => 67, 'name' => 'Siquijor', 'code' => 'SIQ'],
            ['id' => 31, 'name' => 'Dinagat Islands', 'code' => 'DIN'],
            ['id' => 73, 'name' => 'Surigao del Norte', 'code' => 'SDN'],
            ['id' => 82, 'name' => 'Southern Samar', 'code' => 'SSA'],
            ['id' => 83, 'name' => 'Samar', 'code' => 'SAM'],
        ]);

        DB::table('cities')->insert([
            ['id' => 1, 'name' => 'Cebu City', 'province_id' => 25, 'type' => 1],
            ['id' => 2, 'name' => 'Talisay City', 'province_id' => 25, 'type' => 1],
            ['id' => 3, 'name' => 'Catbalogan', 'province_id' => 83, 'type' => 1],
            ['id' => 4, 'name' => 'Calbayog', 'province_id' => 82, 'type' => 1],
            // Same town, two rows, split across the duplicate Samar provinces —
            // exactly the shape the city fold exists for.
            ['id' => 5, 'name' => 'Catbalogan', 'province_id' => 82, 'type' => 1],
        ]);

        DB::table('barangays')->insert([
            ['id' => 11, 'name' => 'Lahug', 'city_id' => 1],
            ['id' => 12, 'name' => 'Tabunok', 'city_id' => 2],
            ['id' => 13, 'name' => 'Poblacion', 'city_id' => 3],
            ['id' => 14, 'name' => 'Bagacay', 'city_id' => 4],
            ['id' => 15, 'name' => 'Mercedes', 'city_id' => 5],
            // Cebu City's second registry row, with no listings at all: the
            // barangay tier's zero-seed case (most of a city's barangays are
            // this, and a missing row reads as "no data" rather than "zero").
            ['id' => 17, 'name' => 'Apas', 'city_id' => 1],
            // Talisay's identical-name pair — the registry carries 363 of
            // these nationwide and a polygon can only ever point at one.
            ['id' => 18, 'name' => 'Lagtang', 'city_id' => 2],
            ['id' => 19, 'name' => 'Lagtang', 'city_id' => 2],
            // Calbayog's Proper/Poblacion siblings. These are NOT twins: the
            // PSA file lists both as barangays of their own and gives each a
            // polygon, so the fold key must keep them apart (the matcher's
            // fullKey() would not — see BarangayNameMatcher::foldKey()).
            ['id' => 20, 'name' => 'Asinan Poblacion', 'city_id' => 4],
            ['id' => 21, 'name' => 'Asinan Proper', 'city_id' => 4],
        ]);

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'For Sale'],
            ['id' => 2, 'name' => 'For Rent'],
            ['id' => 3, 'name' => 'Foreclosure'],
            // Present on purpose: a non-standard category must never be counted.
            ['id' => 4, 'name' => 'Pre-Selling'],
        ]);

        // Cebu and one of the two Catbalogan rows own a polygon; Siquijor and
        // Calbayog do not, so has_boundary / unmapped_listings have something
        // to say.
        DB::table('boundaries')->insert([
            ['id' => 1, 'level' => 'province', 'name' => 'Cebu', 'city_id' => null, 'province_id' => 25, 'barangay_id' => null, 'psgc_code' => null, 'parent_psgc' => null, 'link_how' => null],
            ['id' => 2, 'level' => 'province', 'name' => 'Samar', 'city_id' => null, 'province_id' => 83, 'barangay_id' => null, 'psgc_code' => null, 'parent_psgc' => null, 'link_how' => null],
            ['id' => 3, 'level' => 'city', 'name' => 'Cebu City', 'city_id' => 1, 'province_id' => 25, 'barangay_id' => null, 'psgc_code' => null, 'parent_psgc' => null, 'link_how' => null],
            ['id' => 4, 'level' => 'city', 'name' => 'Talisay City', 'city_id' => 2, 'province_id' => 25, 'barangay_id' => null, 'psgc_code' => null, 'parent_psgc' => null, 'link_how' => null],
            // Points at the id 5 row, so the fold must keep 5 and drop 3.
            ['id' => 5, 'level' => 'city', 'name' => 'Catbalogan', 'city_id' => 5, 'province_id' => 83, 'barangay_id' => null, 'psgc_code' => null, 'parent_psgc' => null, 'link_how' => null],

            // Barangay polygons. Lahug and Poblacion are ordinary links; the
            // Talisay one points at the SECOND Lagtang row (19), so the
            // duplicate fold has to pick 19 over the lower id 18 — the row the
            // map can shade must be the row that holds the count. Apas (17)
            // deliberately owns no polygon.
            ['id' => 6, 'level' => 'barangay', 'name' => 'Lahug', 'city_id' => 1, 'province_id' => 25, 'barangay_id' => 11, 'psgc_code' => 'PH072221026', 'parent_psgc' => 'PH0702217', 'link_how' => 'geo+name'],
            ['id' => 7, 'level' => 'barangay', 'name' => 'Lagtang', 'city_id' => 2, 'province_id' => 25, 'barangay_id' => 19, 'psgc_code' => 'PH072251009', 'parent_psgc' => 'PH0702251', 'link_how' => 'geo+name'],
            ['id' => 8, 'level' => 'barangay', 'name' => 'Poblacion', 'city_id' => 3, 'province_id' => 83, 'barangay_id' => 13, 'psgc_code' => 'PH086001001', 'parent_psgc' => 'PH0860010', 'link_how' => 'name-only'],
            // A registry gap: a real polygon with no `barangays` row behind it.
            // It must never appear as a counts row (it has no id to fold onto).
            ['id' => 9, 'level' => 'barangay', 'name' => 'Banawa', 'city_id' => 1, 'province_id' => 25, 'barangay_id' => null, 'psgc_code' => 'PH072221081', 'parent_psgc' => 'PH0702217', 'link_how' => null],
            // One polygon each for the Proper/Poblacion siblings.
            ['id' => 10, 'level' => 'barangay', 'name' => 'Asinan Poblacion', 'city_id' => 4, 'province_id' => 83, 'barangay_id' => 20, 'psgc_code' => 'PH086002001', 'parent_psgc' => 'PH0860020', 'link_how' => 'geo+name'],
            ['id' => 11, 'level' => 'barangay', 'name' => 'Asinan Proper', 'city_id' => 4, 'province_id' => 83, 'barangay_id' => 21, 'psgc_code' => 'PH086002002', 'parent_psgc' => 'PH0860020', 'link_how' => 'geo+name'],
        ]);

        DB::table('agents')->insert([
            ['id' => 7, 'user_id' => 700, 'status' => 'active'],
            ['id' => 8, 'user_id' => 800, 'status' => 'active'],
            // On no team at all: the listings that prove a leader's scope is a
            // filter and not a no-op.
            ['id' => 9, 'user_id' => 900, 'status' => 'active'],
        ]);
        DB::table('team_agents')->insert([
            ['id' => 1, 'team_id' => 3, 'agent_id' => 7, 'is_leader' => true, 'status' => 'active'],
            ['id' => 2, 'team_id' => 3, 'agent_id' => 8, 'is_leader' => false, 'status' => 'active'],
        ]);
    }

    /** created_at as the UTC literal the column stores, from a Manila instant. */
    private function utc(Carbon $manila): string
    {
        return $manila->copy()->utc()->format('Y-m-d H:i:s');
    }

    private function seedListings(): void
    {
        $midnight = $this->manilaMidnight;

        // [barangay, category, created_at, agent]
        $rows = [
            // Cebu City — one per age bucket, all For Sale.
            [11, 1, $midnight->copy()->addMinutes(30), 7],                 // today
            [11, 1, $midnight->copy()->subDays(3)->addHours(10), 7],       // in 7d + 30d
            [11, 1, $midnight->copy()->subDays(10)->addHours(10), 8],      // in 30d only
            [11, 1, $midnight->copy()->subDays(40)->addHours(10), 8],      // all-time only

            // The window's edge, from both sides. An hour BEFORE Manila
            // midnight is yesterday and must stay out of new_1d; an hour after
            // is today and must be in it. Computed in UTC these two are 16:00
            // and 18:00 of the SAME UTC day, so a UTC-day window would put them
            // in the same bucket and this pair would fail.
            [12, 2, $midnight->copy()->subHour(), 7],
            [12, 2, $midnight->copy()->addHour(), 7],

            // Samar: one listing under each of the two province rows, in two
            // `cities` rows that are the same town.
            [13, 1, $midnight->copy()->subDays(2)->addHours(9), 9],        // city 3, province 83
            [15, 3, $midnight->copy()->subDays(2)->addHours(9), 9],        // city 5, province 82
            [14, 1, $midnight->copy()->subDays(2)->addHours(9), 9],        // city 4 (Calbayog), province 82

            // Never counted: a non-standard category.
            [11, 4, $midnight->copy()->subHours(2), 7],
        ];

        $propertyId = 100;
        $listingId = 100;

        foreach ($rows as [$barangayId, $categoryId, $createdAt, $agentId]) {
            DB::table('properties')->insert([
                'id' => $propertyId,
                'project_id' => null,
                'address_id' => $barangayId,
                'status' => 'active',
                'created_at' => $this->utc($createdAt),
                'updated_at' => $this->utc($createdAt),
            ]);
            DB::table('listings')->insert([
                'id' => $listingId,
                'category_id' => $categoryId,
                'property_id' => $propertyId,
                'agent_id' => $agentId,
                'created_at' => $this->utc($createdAt),
                'updated_at' => $this->utc($createdAt),
            ]);
            $propertyId++;
            $listingId++;
        }

        // Soft-deleted listing + deleted-status property: both must be invisible.
        DB::table('properties')->insert([
            'id' => 900, 'address_id' => 11, 'status' => 'active',
            'created_at' => $this->utc($midnight), 'updated_at' => $this->utc($midnight),
        ]);
        DB::table('listings')->insert([
            'id' => 900, 'category_id' => 1, 'property_id' => 900, 'agent_id' => 7,
            'created_at' => $this->utc($midnight), 'updated_at' => $this->utc($midnight),
            'deleted_at' => $this->utc($midnight),
        ]);
        DB::table('properties')->insert([
            'id' => 901, 'address_id' => 11, 'status' => 'deleted',
            'created_at' => $this->utc($midnight), 'updated_at' => $this->utc($midnight),
        ]);
        DB::table('listings')->insert([
            'id' => 901, 'category_id' => 1, 'property_id' => 901, 'agent_id' => 7,
            'created_at' => $this->utc($midnight), 'updated_at' => $this->utc($midnight),
        ]);
    }

    private function user(string $role, int $id): User
    {
        $user = new User;
        $user->id = $id;
        $user->setRelation('role', (new Role)->forceFill(['name' => $role]));

        return $user;
    }

    /** Call the controller the way the route would, minus the HTTP stack. */
    private function heatmap(array $query = [], string $role = 'admin', int $userId = 1): array
    {
        $request = Request::create('/api/listings/insights/heatmap', 'GET', $query);
        $request->setUserResolver(fn () => $this->user($role, $userId));

        $response = app(ListingController::class)->insightsHeatmap($request, app(ListingHeatmapService::class));

        return $response->getData(true);
    }

    private function areaNamed(array $payload, string $name): ?array
    {
        foreach ($payload['data'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }

    public function test_province_rows_carry_the_contract_shape_and_consistent_by_category_sums(): void
    {
        $payload = $this->heatmap(['level' => 'province']);

        $this->assertSame('province', $payload['level']);
        $this->assertNull($payload['province_id']);
        $this->assertSame(ListingHeatmapService::TTL, $payload['ttl']);
        $this->assertSame('admin', $payload['scope']);
        $this->assertArrayHasKey('today_from', $payload['meta']['windows']);
        $this->assertArrayHasKey('compute_ms', $payload['meta']);

        $cebu = $this->areaNamed($payload, 'Cebu');
        $this->assertNotNull($cebu);
        // One shape for all three tiers: city_id / city_name are on every row
        // and are null above barangay level. The frontend reads the row by key
        // and renders a City column from it, so a tier that omitted them would
        // be a different contract, not a smaller one.
        $this->assertSame([
            'id', 'name', 'province_id', 'province_name', 'city_id', 'city_name', 'total',
            'for_sale', 'for_rent', 'foreclosure', 'new_1d', 'new_7d', 'new_30d',
            'by_category', 'has_boundary',
        ], array_keys($cebu));
        $this->assertNull($cebu['city_id']);
        $this->assertNull($cebu['city_name']);
        $this->assertNull($payload['city_id'], 'the snapshot carries a city_id too, null above barangay level');

        // 4 Cebu City For Sale + 2 Talisay For Rent; the Pre-Selling row and
        // both deleted rows are not listings as far as this endpoint is told.
        $this->assertSame(6, $cebu['total']);
        $this->assertSame(4, $cebu['for_sale']);
        $this->assertSame(2, $cebu['for_rent']);
        $this->assertSame(0, $cebu['foreclosure']);
        $this->assertTrue($cebu['has_boundary']);

        // The nested block and the flat fields are two views of one query, so
        // they must never disagree — this is what the category toggle reads.
        foreach ($payload['data'] as $row) {
            $this->assertSame(
                $row['total'],
                $row['by_category']['for_sale']['total']
                    + $row['by_category']['for_rent']['total']
                    + $row['by_category']['foreclosure']['total'],
                "by_category totals must sum to total for {$row['name']}"
            );
            foreach (['new_1d', 'new_7d', 'new_30d'] as $window) {
                $this->assertSame(
                    $row[$window],
                    $row['by_category']['for_sale'][$window]
                        + $row['by_category']['for_rent'][$window]
                        + $row['by_category']['foreclosure'][$window],
                    "by_category {$window} must sum to {$window} for {$row['name']}"
                );
            }
            $this->assertSame($row['for_sale'], $row['by_category']['for_sale']['total']);
            $this->assertSame($row['for_rent'], $row['by_category']['for_rent']['total']);
            $this->assertSame($row['foreclosure'], $row['by_category']['foreclosure']['total']);
        }

        // KPI identity the page prints out loud.
        $this->assertSame(9, $payload['totals']['listings']);
        $this->assertSame(
            $payload['totals']['listings'],
            array_sum(array_column($payload['data'], 'total'))
        );
        $this->assertSame(6, $payload['totals']['max']['total']);
    }

    public function test_a_province_with_no_listings_is_still_on_the_map_as_a_zero(): void
    {
        $payload = $this->heatmap(['level' => 'province']);

        // Siquijor on purpose: a province that owns no cities AND whose name is
        // not an alias source, so this test measures zero-seeding and nothing
        // else. (Dinagat would look like the same case and is not — see the
        // fold test below.)
        $siquijor = $this->areaNamed($payload, 'Siquijor');
        $this->assertNotNull($siquijor, 'a province with no listings must be seeded, not dropped');
        $this->assertSame(0, $siquijor['total']);
        $this->assertSame(67, $siquijor['id']);
        $this->assertFalse($siquijor['has_boundary']);

        // Four canonical provinces: Cebu, Siquijor, Surigao del Norte, Samar.
        // 82 is not one of them — it is Samar wearing a second id — and neither
        // is 31, which the alias table folds onto 73.
        $this->assertSame(4, $payload['totals']['areas']);
        $this->assertSame(4, count($payload['data']));
        $this->assertNotContains(82, array_column($payload['data'], 'id'));
    }

    public function test_a_province_row_whose_name_is_an_alias_source_never_reaches_the_map(): void
    {
        // The hazard ProvinceCanonicalizer's docblock warns about, acted out.
        // Dinagat Islands is a real row (31) AND an alias source, so idMap()
        // folds it onto Surigao del Norte (73). That is correct today only
        // because 31 owns no cities. Give it one — a one-line admin data fix
        // that looks entirely safe — and its listings are counted and shaded
        // under its neighbour, while the province itself is nowhere on the map.
        //
        // This test asserts the wrong-looking behaviour on purpose: it is what
        // production does, and the day someone moves Dinagat's five towns onto
        // 31 this is the test that has to be read and the alias that has to go.
        $when = $this->manilaMidnight->copy()->subDays(2);

        DB::table('cities')->insert(['id' => 6, 'name' => 'Loreto', 'province_id' => 31, 'type' => 1]);
        DB::table('barangays')->insert(['id' => 16, 'name' => 'Santiago', 'city_id' => 6]);
        DB::table('properties')->insert([
            'id' => 200, 'address_id' => 16, 'status' => 'active',
            'created_at' => $this->utc($when), 'updated_at' => $this->utc($when),
        ]);
        DB::table('listings')->insert([
            'id' => 200, 'category_id' => 1, 'property_id' => 200, 'agent_id' => 9,
            'created_at' => $this->utc($when), 'updated_at' => $this->utc($when),
        ]);

        $payload = $this->heatmap(['level' => 'province']);

        $this->assertNull(
            $this->areaNamed($payload, 'Dinagat Islands'),
            'an alias SOURCE that still has a province row is erased from the map'
        );
        $this->assertNotContains(31, array_column($payload['data'], 'id'));

        $surigao = $this->areaNamed($payload, 'Surigao del Norte');
        $this->assertNotNull($surigao);
        $this->assertSame(1, $surigao['total'], "Dinagat's listing is counted under its alias target");
    }

    public function test_mirror_guard_the_only_province_row_an_alias_folds_away_is_the_documented_one(): void
    {
        // The DB-backed half of ProvinceCanonicalizerTest's contract: an alias
        // source that ALSO exists as a province row silently deletes that
        // province from the heatmap. Only one does today, deliberately, and
        // this fixture mirrors the live `provinces` table for exactly those
        // rows. Adding a row for another source (a real Davao Occidental, say)
        // means adding it here too — and that is the moment the matching alias
        // must be deleted, which is what this assertion exists to force.
        $idToName = [];
        foreach (DB::table('provinces')->orderBy('id')->get(['id', 'name']) as $row) {
            $idToName[(int) $row->id] = (string) $row->name;
        }

        $folded = [];
        foreach ($idToName as $id => $name) {
            $normalized = IslandMap::normalize($name);
            if (array_key_exists($normalized, ProvinceCanonicalizer::aliases())) {
                $folded[$normalized] = $id;
            }
        }

        // Two rows fold today, and only one of them is a hazard:
        //
        //   'southern samar' (82) is BENIGN. It is the same real place as
        //   Samar (83) under a legacy spelling, so folding it is the entire
        //   point — two rows, one province, one shaded area.
        //
        //   'dinagat islands' (31) is the HAZARD. It is a different real place
        //   from Surigao del Norte (73), folded only because 31 owns no cities
        //   and its towns sit under 73. Move those towns and the province
        //   disappears from the map while its listings shade its neighbour.
        //
        // A third entry appearing here is the alarm: a row for 'davao
        // occidental', 'cotabato city' or 'city of isabela' means that place
        // now exists in its own right, and its alias must be deleted in the
        // same change.
        $this->assertSame(
            ['dinagat islands' => 31, 'southern samar' => 82],
            $folded,
            'a province row whose name is an alias source is swallowed by its target — delete the alias'
        );

        // And it really does fold: 31 is not its own canonical id.
        $this->assertSame(73, ProvinceCanonicalizer::idMap($idToName)[31]);
    }

    public function test_the_duplicate_samar_rows_are_counted_as_one_province(): void
    {
        $payload = $this->heatmap(['level' => 'province']);

        $samar = $this->areaNamed($payload, 'Samar');
        $this->assertNotNull($samar);
        $this->assertSame(83, $samar['id'], 'the canonical id is the row actually named Samar');
        // One listing filed under 83 + two filed under 82.
        $this->assertSame(3, $samar['total']);
        $this->assertSame(2, $samar['for_sale']);
        $this->assertSame(1, $samar['foreclosure']);
        $this->assertTrue($samar['has_boundary']);
    }

    public function test_scoping_to_either_samar_id_returns_the_same_city_set(): void
    {
        $byDuplicate = $this->heatmap(['level' => 'city', 'province_id' => 82]);
        $byCanonical = $this->heatmap(['level' => 'city', 'province_id' => 83]);

        $this->assertSame(83, $byDuplicate['province_id']);
        $this->assertSame(83, $byCanonical['province_id']);
        $this->assertSame(
            array_column($byCanonical['data'], 'id'),
            array_column($byDuplicate['data'], 'id')
        );
        $this->assertSame(3, $byCanonical['totals']['listings']);
    }

    public function test_duplicate_city_rows_fold_onto_the_id_the_polygon_references(): void
    {
        $payload = $this->heatmap(['level' => 'city', 'province_id' => 83]);

        $catbalogan = $this->areaNamed($payload, 'Catbalogan');
        $this->assertNotNull($catbalogan);
        $this->assertSame(5, $catbalogan['id'], 'the survivor is the row boundaries.city_id points at');
        $this->assertSame(2, $catbalogan['total'], 'both rows\' listings land on the surviving id');
        $this->assertTrue($catbalogan['has_boundary']);

        // Two areas, not three: Catbalogan 3 and Catbalogan 5 are one town.
        $this->assertSame(2, $payload['totals']['areas']);

        // Calbayog has listings but no polygon — that is what "unmapped" means,
        // and it is why the KPI strip can say so without loading any geometry.
        $calbayog = $this->areaNamed($payload, 'Calbayog');
        $this->assertNotNull($calbayog);
        $this->assertFalse($calbayog['has_boundary']);
        $this->assertSame(1, $payload['totals']['unmapped_listings']);
        $this->assertSame(0, $payload['totals']['unknown_listings']);
    }

    public function test_new_today_follows_the_manila_calendar_day_not_the_utc_one(): void
    {
        $payload = $this->heatmap(['level' => 'province']);
        $cebu = $this->areaNamed($payload, 'Cebu');

        // Of the six Cebu listings: 00:30 and 01:00 today are "today"; 23:00
        // yesterday is not, although all three share one UTC date.
        $this->assertSame(2, $cebu['new_1d']);
        // + the 3-days-ago row and the hour-before-midnight row.
        $this->assertSame(4, $cebu['new_7d']);
        // + the 10-days-ago row. The 40-days-ago row is outside every window.
        $this->assertSame(5, $cebu['new_30d']);
        $this->assertSame(6, $cebu['total']);

        // The response states the cut-offs it used, in Manila time, so the
        // tooltip can say "since 12:00 AM PHT" without re-deriving them.
        $this->assertSame($this->manilaMidnight->toIso8601String(), $payload['meta']['windows']['today_from']);
        $this->assertSame(
            $this->manilaMidnight->copy()->subDays(6)->toIso8601String(),
            $payload['meta']['windows']['d7_from']
        );
        $this->assertSame(
            $this->manilaMidnight->copy()->subDays(29)->toIso8601String(),
            $payload['meta']['windows']['d30_from']
        );
    }

    public function test_a_team_leader_sees_only_their_team(): void
    {
        $payload = $this->heatmap(['level' => 'province'], 'agent', 700);

        $this->assertSame('team', $payload['scope']);

        // Team 3 is agents 7 and 8, who between them own all six Cebu
        // listings. Agent 9 is on no team, so their three Samar listings are
        // not the leader's to see — and a leader's scope covers the whole
        // team, not just themselves.
        $cebu = $this->areaNamed($payload, 'Cebu');
        $this->assertSame(6, $cebu['total']);
        $this->assertSame(0, $this->areaNamed($payload, 'Samar')['total']);
        $this->assertSame(6, $payload['totals']['listings']);

        // Zero-seeding is scope-independent: a leader with nothing in Siquijor
        // still sees Siquijor, greyed.
        $this->assertNotNull($this->areaNamed($payload, 'Siquijor'));
    }

    public function test_a_user_who_leads_nobody_is_refused(): void
    {
        $this->expectException(HttpException::class);

        // user 800 is on a team but does not lead it.
        $this->heatmap(['level' => 'province'], 'agent', 800);
    }

    public function test_a_second_call_inside_the_ttl_does_not_re_run_the_aggregate(): void
    {
        $this->heatmap(['level' => 'province']);

        $aggregateQueries = [];
        DB::listen(function ($query) use (&$aggregateQueries) {
            if (str_contains($query->sql, 'listings') || str_contains($query->sql, 'boundaries')) {
                $aggregateQueries[] = $query->sql;
            }
        });

        $payload = $this->heatmap(['level' => 'province']);

        // The validator still checks province_id against `provinces`, so this
        // is not "zero queries" — it is "zero work the cache was built to
        // avoid". The 60 s poll must never touch listings again.
        $this->assertSame([], $aggregateQueries);
        $this->assertTrue($payload['meta']['cached']);
        $this->assertFalse($payload['meta']['stale']);
    }

    public function test_the_cache_key_separates_admins_from_team_leaders(): void
    {
        $admin = $this->heatmap(['level' => 'province']);
        $leader = $this->heatmap(['level' => 'province'], 'agent', 700);

        $this->assertSame(9, $admin['totals']['listings']);
        $this->assertSame(6, $leader['totals']['listings'], 'a leader must not be served the admin payload');
    }

    public function test_an_unknown_province_id_is_rejected_before_any_work(): void
    {
        $this->expectException(ValidationException::class);

        $this->heatmap(['level' => 'city', 'province_id' => 4242]);
    }

    public function test_barangay_rows_carry_their_city_and_sum_to_the_city_row(): void
    {
        $payload = $this->heatmap(['level' => 'barangay', 'city_id' => 1]);

        $this->assertSame('barangay', $payload['level'], 'the response must echo the level that was asked for');
        $this->assertSame(1, $payload['city_id']);
        $this->assertSame(25, $payload['province_id'], 'the snapshot still names the province the city sits in');

        $lahug = $this->areaNamed($payload, 'Lahug');
        $this->assertNotNull($lahug);
        $this->assertSame([
            'id', 'name', 'province_id', 'province_name', 'city_id', 'city_name', 'total',
            'for_sale', 'for_rent', 'foreclosure', 'new_1d', 'new_7d', 'new_30d',
            'by_category', 'has_boundary',
        ], array_keys($lahug));
        $this->assertSame(11, $lahug['id']);
        $this->assertSame(4, $lahug['total']);
        $this->assertTrue($lahug['has_boundary']);
        // Every row names the GROUP's city and province, not whichever
        // duplicate `cities` row the listing happened to be filed under.
        $this->assertSame(1, $lahug['city_id']);
        $this->assertSame('Cebu City', $lahug['city_name']);
        $this->assertSame(25, $lahug['province_id']);
        $this->assertSame('Cebu', $lahug['province_name']);

        // The parity the admin reads with their own eyes: they click Cebu City
        // on the city map and the barangays they get must total the number the
        // row they clicked was showing.
        $cityRow = $this->areaNamed($this->heatmap(['level' => 'city', 'province_id' => 25]), 'Cebu City');
        $this->assertSame(4, $cityRow['total']);
        $this->assertSame($cityRow['total'], array_sum(array_column($payload['data'], 'total')));
        $this->assertSame($cityRow['total'], $payload['totals']['listings']);

        // The registry-gap polygon (Banawa, a real shape with no `barangays`
        // row) is geometry only: it is never a counts row, because there is no
        // id for a count to fold onto.
        $this->assertNull($this->areaNamed($payload, 'Banawa'));
        $this->assertSame(2, $payload['totals']['areas']);
    }

    public function test_a_barangay_with_no_listings_is_still_on_the_map_as_a_zero(): void
    {
        $payload = $this->heatmap(['level' => 'barangay', 'city_id' => 1]);

        // Most of a city's barangays hold nothing; dropping them would read as
        // "no data here" on a map whose whole claim is "this is all of it".
        $apas = $this->areaNamed($payload, 'Apas');
        $this->assertNotNull($apas, 'a barangay with no listings must be seeded, not dropped');
        $this->assertSame(17, $apas['id']);
        $this->assertSame(0, $apas['total']);
        $this->assertSame(1, $apas['city_id']);
        $this->assertFalse($apas['has_boundary']);
    }

    public function test_duplicate_barangay_rows_fold_onto_the_id_the_polygon_references(): void
    {
        // Talisay really does carry two "Lagtang" rows with listings on both.
        // Two half-filled rows would paint one and grey the other.
        $when = $this->manilaMidnight->copy()->subDays(2);
        $propertyId = 300;

        foreach ([18, 19] as $barangayId) {
            DB::table('properties')->insert([
                'id' => $propertyId, 'address_id' => $barangayId, 'status' => 'active',
                'created_at' => $this->utc($when), 'updated_at' => $this->utc($when),
            ]);
            DB::table('listings')->insert([
                'id' => $propertyId, 'category_id' => 1, 'property_id' => $propertyId, 'agent_id' => 7,
                'created_at' => $this->utc($when), 'updated_at' => $this->utc($when),
            ]);
            $propertyId++;
        }

        $payload = $this->heatmap(['level' => 'barangay', 'city_id' => 2]);

        $lagtang = $this->areaNamed($payload, 'Lagtang');
        $this->assertNotNull($lagtang);
        $this->assertSame(19, $lagtang['id'], 'the survivor is the row boundaries.barangay_id points at, not the lower id');
        $this->assertSame(2, $lagtang['total'], "both rows' listings land on the surviving id");
        $this->assertTrue($lagtang['has_boundary']);

        // Two areas, not three: the twins are one barangay.
        $this->assertSame(2, $payload['totals']['areas']);

        // And the fold must not break parity with the tier above it.
        $cityRow = $this->areaNamed($this->heatmap(['level' => 'city', 'province_id' => 25]), 'Talisay City');
        $this->assertSame(4, $cityRow['total']);
        $this->assertSame($cityRow['total'], array_sum(array_column($payload['data'], 'total')));
    }

    public function test_a_proper_poblacion_sibling_pair_stays_two_rows_with_two_polygons(): void
    {
        // The mirror of the twin fold above, and the case it must NOT swallow.
        // "Asinan Poblacion" and "Asinan Proper" are two barangays in the PSA
        // file and own a polygon each. Folding them onto one counts row left
        // the second polygon with no row to resolve, so the map drew a real
        // barangay as "not in the registry" and shaded it as a zero.
        $payload = $this->heatmap(['level' => 'barangay', 'city_id' => 4]);

        $poblacion = $this->areaNamed($payload, 'Asinan Poblacion');
        $proper = $this->areaNamed($payload, 'Asinan Proper');

        $this->assertNotNull($poblacion);
        $this->assertNotNull($proper);
        $this->assertSame(20, $poblacion['id']);
        $this->assertSame(21, $proper['id']);
        $this->assertTrue($poblacion['has_boundary']);
        $this->assertTrue($proper['has_boundary'], 'the second polygon has a counts row of its own to resolve against');

        // Bagacay plus the two siblings — three rows, not two.
        $this->assertSame(['Bagacay', 'Asinan Poblacion', 'Asinan Proper'], $this->names($payload));
        $this->assertSame(3, $payload['totals']['areas']);

        // Neither sibling carries inventory, so the city row is unchanged.
        $cityRow = $this->areaNamed($this->heatmap(['level' => 'city', 'province_id' => 83]), 'Calbayog');
        $this->assertSame($cityRow['total'], array_sum(array_column($payload['data'], 'total')));
    }

    public function test_the_barangay_tier_covers_the_whole_city_group_under_one_id(): void
    {
        $byDuplicate = $this->heatmap(['level' => 'barangay', 'city_id' => 3]);
        $byCanonical = $this->heatmap(['level' => 'barangay', 'city_id' => 5]);

        // Catbalogan 3 and Catbalogan 5 are one town; the polygon points at 5,
        // so that is the id both requests resolve to — and therefore the single
        // cache entry they share.
        $this->assertSame(5, $byDuplicate['city_id']);
        $this->assertSame(5, $byCanonical['city_id']);
        $this->assertSame(83, $byDuplicate['province_id']);
        $this->assertSame($byCanonical['data'], $byDuplicate['data']);

        // Both twin rows' barangays are in scope, or the barangays of a twin
        // town would not sum to the city row.
        $this->assertSame(['Mercedes', 'Poblacion'], $this->names($byCanonical));
        $this->assertSame(1, $this->areaNamed($byCanonical, 'Poblacion')['total']);
        $this->assertSame(1, $this->areaNamed($byCanonical, 'Mercedes')['total']);

        $cityRow = $this->areaNamed($this->heatmap(['level' => 'city', 'province_id' => 83]), 'Catbalogan');
        $this->assertSame($cityRow['total'], $byCanonical['totals']['listings']);
    }

    public function test_each_city_gets_its_own_cache_entry(): void
    {
        $cebuCity = $this->heatmap(['level' => 'barangay', 'city_id' => 1]);

        $aggregates = [];
        DB::listen(function ($query) use (&$aggregates) {
            if (str_contains($query->sql, 'listings')) {
                $aggregates[] = $query->sql;
            }
        });

        // A second city must not be served the first city's payload — the bug
        // a cache key without a city slot would hand the admin silently.
        $talisay = $this->heatmap(['level' => 'barangay', 'city_id' => 2]);
        $this->assertNotSame([], $aggregates, 'a different city has to be computed, not read back');
        $this->assertSame(['Lahug', 'Apas'], $this->names($cebuCity));
        $this->assertSame(['Tabunok', 'Lagtang'], $this->names($talisay));

        // …and going back to the first city is still a cache hit.
        $aggregates = [];
        $again = $this->heatmap(['level' => 'barangay', 'city_id' => 1]);
        $this->assertSame([], $aggregates);
        $this->assertSame($cebuCity['data'], $again['data']);
        $this->assertTrue($again['meta']['cached']);
    }

    public function test_a_stray_province_id_is_ignored_at_barangay_level(): void
    {
        $plain = $this->heatmap(['level' => 'barangay', 'city_id' => 1]);

        $aggregates = [];
        DB::listen(function ($query) use (&$aggregates) {
            if (str_contains($query->sql, 'listings')) {
                $aggregates[] = $query->sql;
            }
        });

        // Scope is derived from the level, so a province_id left over from the
        // breadcrumb above must neither change the answer nor fork the key
        // into a second entry holding a copy of it.
        $stray = $this->heatmap(['level' => 'barangay', 'city_id' => 1, 'province_id' => 83]);

        $this->assertSame([], $aggregates, 'a stray province_id must not fork the cache key');
        $this->assertSame($plain['data'], $stray['data']);
        $this->assertSame(25, $stray['province_id'], 'the province is the one the city is in, not the one that was sent');
    }

    public function test_the_barangay_tier_refuses_to_answer_without_a_city(): void
    {
        // 422, not a nationwide 42k-row answer nobody asked for. The frontend
        // relies on this being a validation failure.
        $this->expectException(ValidationException::class);

        $this->heatmap(['level' => 'barangay']);
    }

    public function test_an_unknown_city_id_is_rejected_before_any_work(): void
    {
        $this->expectException(ValidationException::class);

        $this->heatmap(['level' => 'barangay', 'city_id' => 4242]);
    }

    public function test_a_team_leader_sees_only_their_team_at_barangay_level(): void
    {
        $payload = $this->heatmap(['level' => 'barangay', 'city_id' => 3], 'agent', 700);

        $this->assertSame('team', $payload['scope']);

        // Catbalogan's two listings belong to agent 9, who is on no team.
        $this->assertSame(0, $payload['totals']['listings']);

        // Zero-seeding is scope-independent, exactly as it is above: the
        // leader still sees the barangays, greyed.
        $this->assertSame(['Mercedes', 'Poblacion'], $this->names($payload));
        $this->assertSame(0, $this->areaNamed($payload, 'Poblacion')['total']);
    }

    public function test_a_user_who_leads_nobody_is_refused_at_barangay_level(): void
    {
        $this->expectException(HttpException::class);

        $this->heatmap(['level' => 'barangay', 'city_id' => 1], 'agent', 800);
    }

    /** Row names in response order (biggest first, Unknown last). */
    private function names(array $payload): array
    {
        return array_column($payload['data'], 'name');
    }
}
