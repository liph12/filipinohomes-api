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
        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->string('level');
            $table->string('name');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
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
            ['id' => 1, 'level' => 'province', 'name' => 'Cebu', 'city_id' => null, 'province_id' => 25],
            ['id' => 2, 'level' => 'province', 'name' => 'Samar', 'city_id' => null, 'province_id' => 83],
            ['id' => 3, 'level' => 'city', 'name' => 'Cebu City', 'city_id' => 1, 'province_id' => 25],
            ['id' => 4, 'level' => 'city', 'name' => 'Talisay City', 'city_id' => 2, 'province_id' => 25],
            // Points at the id 5 row, so the fold must keep 5 and drop 3.
            ['id' => 5, 'level' => 'city', 'name' => 'Catbalogan', 'city_id' => 5, 'province_id' => 83],
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
        $this->assertSame([
            'id', 'name', 'province_id', 'province_name', 'total',
            'for_sale', 'for_rent', 'foreclosure', 'new_1d', 'new_7d', 'new_30d',
            'by_category', 'has_boundary',
        ], array_keys($cebu));

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
}
