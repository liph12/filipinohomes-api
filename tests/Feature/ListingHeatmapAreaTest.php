<?php

namespace Tests\Feature;

use App\Http\Controllers\ListingController;
use App\Models\Role;
use App\Models\User;
use App\Services\Listing\ListingHeatmapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * One heatmap area as a set of listings: ListingHeatmapService::areaListingsQuery(),
 * the details card it feeds (GET /listings/insights/heatmap/area), and the All
 * Listings "View these listings" link that filters by it (GET /all-listings).
 *
 * The contract is a single sentence: the listings behind an area are exactly
 * the listings its number on the map counts. So the central test compares the
 * two for EVERY row the heatmap returns, at every tier, with and without a
 * range, per category, for an admin and for a team leader.
 *
 * The fixture is built to make a lazy definition of "the area" fail. Beside
 * the folded twins (Samar 82/83, Catbalogan 3/5, Talisay's two `cities` rows,
 * the Lagtang barangays across them), it carries the data the registry should
 * not hold but can: a project filed under Bohol whose town is Cebu's Carmen,
 * a Cebu City project whose units' address barangays are in Talisay (one of
 * them sharing a name with a Cebu City barangay), a deleted project, and a
 * project with a province but no town.
 *
 * Builds its own minimal tables (the migrations are MySQL-only), like
 * ListingHeatmapTest. All Listings needs a few more: the inquiry-count
 * subquery and the eager loads read them.
 */
class ListingHeatmapAreaTest extends TestCase
{
    private const CATEGORY_NAMES = ['for_sale' => 'For Sale', 'for_rent' => 'For Rent', 'foreclosure' => 'Foreclosed'];

    /** Every town with a barangay tier in the fixture. */
    private const CITIES = [1, 2, 3, 4, 7, 9, 10];

    /** Team 3, led by user 700 (agent 7). */
    private const TEAM = [7, 8];

    /** Asia/Manila start of the current calendar day — the service's "today". */
    private Carbon $manilaMidnight;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->manilaMidnight = Carbon::now('Asia/Manila')->startOfDay();

        $this->createTables();
        $this->seedGeography();
        $this->seedListings();
    }

    private function createTables(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('province_id');
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
            $table->unsignedBigInteger('property_subtype_id');
            $table->decimal('floor_area', 11, 2)->default(0);
            $table->decimal('lot_area', 11, 2)->default(0);
            $table->unsignedInteger('bedroom_count')->nullable();
            $table->unsignedInteger('bathroom_count')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('property_attribute_id')->nullable();
            $table->string('status')->default('active');
            $table->string('ats_status')->nullable();
            $table->text('geo_coordinates')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('name')->nullable();
            $table->decimal('price', 20, 2)->default(0);
            $table->string('visibility')->default('public');
            $table->string('verification_status')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->text('featured_photo')->nullable();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->string('photos_migration_note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        // No `geom`: the counts and the area query only read the id columns.
        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->string('level');
            $table->string('name');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
        });
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('active');
            $table->string('region')->nullable();
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
        // Read by All Listings' inquiries_count subquery, never written here.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('conversation_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedGeography(): void
    {
        DB::table('provinces')->insert([
            ['id' => 12, 'name' => 'Bohol'],
            ['id' => 25, 'name' => 'Cebu'],
            // An alias source with a row of its own: folds onto Surigao del Norte (73).
            ['id' => 31, 'name' => 'Dinagat Islands'],
            // No towns at all: the zero-seed case.
            ['id' => 67, 'name' => 'Siquijor'],
            ['id' => 73, 'name' => 'Surigao del Norte'],
            // One real place, two rows; 83 is the canonical one.
            ['id' => 82, 'name' => 'Southern Samar'],
            ['id' => 83, 'name' => 'Samar'],
        ]);

        DB::table('cities')->insert([
            ['id' => 1, 'name' => 'Cebu City', 'province_id' => 25],
            // One town, two rows: both names normalize to "talisay".
            ['id' => 2, 'name' => 'Talisay City', 'province_id' => 25],
            ['id' => 8, 'name' => 'City of Talisay', 'province_id' => 25],
            // One town, two rows, split across the Samar twins.
            ['id' => 3, 'name' => 'Catbalogan', 'province_id' => 83],
            ['id' => 5, 'name' => 'Catbalogan', 'province_id' => 82],
            ['id' => 4, 'name' => 'Calbayog', 'province_id' => 82],
            ['id' => 7, 'name' => 'Loreto', 'province_id' => 31],
            // Two different towns that share a name, in two provinces.
            ['id' => 9, 'name' => 'Carmen', 'province_id' => 25],
            ['id' => 10, 'name' => 'Carmen', 'province_id' => 12],
        ]);

        DB::table('barangays')->insert([
            ['id' => 11, 'name' => 'Lahug', 'city_id' => 1],
            // No listings of its own; a Talisay "Apas" folds onto it (listing 109).
            ['id' => 17, 'name' => 'Apas', 'city_id' => 1],
            ['id' => 12, 'name' => 'Tabunok', 'city_id' => 2],
            // Twins inside one town's two rows: 18 + 19 in row 2, 24 in row 8. 19 owns the polygon.
            ['id' => 18, 'name' => 'Lagtang', 'city_id' => 2],
            ['id' => 19, 'name' => 'Lagtang', 'city_id' => 2],
            ['id' => 24, 'name' => 'Lagtang', 'city_id' => 8],
            ['id' => 23, 'name' => 'Apas', 'city_id' => 2],
            ['id' => 25, 'name' => 'Bulacao', 'city_id' => 8],
            ['id' => 13, 'name' => 'Poblacion', 'city_id' => 3],
            ['id' => 15, 'name' => 'Mercedes', 'city_id' => 5],
            ['id' => 14, 'name' => 'Bagacay', 'city_id' => 4],
            // Siblings, NOT twins: two barangays, two polygons.
            ['id' => 20, 'name' => 'Asinan Poblacion', 'city_id' => 4],
            ['id' => 21, 'name' => 'Asinan Proper', 'city_id' => 4],
            ['id' => 16, 'name' => 'Santiago', 'city_id' => 7],
            ['id' => 26, 'name' => 'Poblacion', 'city_id' => 9],
            ['id' => 27, 'name' => 'Poblacion', 'city_id' => 10],
        ]);

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'For Sale'],
            ['id' => 2, 'name' => 'For Rent'],
            ['id' => 3, 'name' => 'Foreclosed'],
            // Never counted by the heatmap; All Listings shows it by default.
            ['id' => 4, 'name' => 'Pre-Selling'],
        ]);

        DB::table('projects')->insert([
            ['id' => 1, 'name' => 'Horizon', 'city_id' => 1, 'prov_id' => 25, 'deleted_at' => null],
            // Town set to Cebu's Carmen, province to Bohol. The city fold keys on
            // the LISTING's province, so this one is counted on Bohol's Carmen.
            ['id' => 2, 'name' => 'Carmen Heights', 'city_id' => 9, 'prov_id' => 12, 'deleted_at' => null],
            // In Cebu City; its units' address barangays are Talisay's.
            ['id' => 3, 'name' => 'Cebu Tower', 'city_id' => 1, 'prov_id' => 25, 'deleted_at' => null],
            // Deleted: its listings resolve through their own barangay instead.
            ['id' => 4, 'name' => 'Old Phase', 'city_id' => 1, 'prov_id' => 25, 'deleted_at' => $this->utc($this->manilaMidnight)],
            // A province (83) and no town: the town comes from the barangay (Calbayog, filed under 82).
            ['id' => 5, 'name' => 'Samar Estates', 'city_id' => null, 'prov_id' => 83, 'deleted_at' => null],
        ]);

        DB::table('boundaries')->insert([
            ['level' => 'province', 'name' => 'Cebu', 'city_id' => null, 'province_id' => 25, 'barangay_id' => null],
            ['level' => 'province', 'name' => 'Samar', 'city_id' => null, 'province_id' => 83, 'barangay_id' => null],
            ['level' => 'city', 'name' => 'Cebu City', 'city_id' => 1, 'province_id' => 25, 'barangay_id' => null],
            ['level' => 'city', 'name' => 'Talisay City', 'city_id' => 2, 'province_id' => 25, 'barangay_id' => null],
            ['level' => 'city', 'name' => 'Catbalogan', 'city_id' => 5, 'province_id' => 83, 'barangay_id' => null],
            ['level' => 'city', 'name' => 'Carmen', 'city_id' => 10, 'province_id' => 12, 'barangay_id' => null],
            ['level' => 'barangay', 'name' => 'Lahug', 'city_id' => 1, 'province_id' => 25, 'barangay_id' => 11],
            ['level' => 'barangay', 'name' => 'Lagtang', 'city_id' => 2, 'province_id' => 25, 'barangay_id' => 19],
            ['level' => 'barangay', 'name' => 'Poblacion', 'city_id' => 3, 'province_id' => 83, 'barangay_id' => 13],
            ['level' => 'barangay', 'name' => 'Asinan Poblacion', 'city_id' => 4, 'province_id' => 83, 'barangay_id' => 20],
            ['level' => 'barangay', 'name' => 'Asinan Proper', 'city_id' => 4, 'province_id' => 83, 'barangay_id' => 21],
        ]);

        DB::table('agents')->insert([
            ['id' => 7, 'user_id' => 700],
            ['id' => 8, 'user_id' => 800],
            // On no team.
            ['id' => 9, 'user_id' => 900],
        ]);
        DB::table('team_agents')->insert([
            ['team_id' => 3, 'agent_id' => 7, 'is_leader' => true, 'status' => 'active'],
            ['team_id' => 3, 'agent_id' => 8, 'is_leader' => false, 'status' => 'active'],
        ]);

        DB::table('property_types')->insert(['id' => 1, 'name' => 'House']);
        DB::table('property_subtypes')->insert(['id' => 1, 'name' => 'House and Lot', 'property_type_id' => 1]);
        DB::table('property_attributes')->insert(['id' => 1, 'property_subtype_id' => 1, 'floor_area' => 80, 'lot_area' => 120]);
    }

    private function seedListings(): void
    {
        // Days from today's Manila midnight, plus minutes into that day (10:00 by default).
        $at = fn (int $days, int $minutes = 600) => $this->manilaMidnight->copy()->addDays($days)->addMinutes($minutes);

        // id => [address barangay, category, created, agent, extras]
        $rows = [
            // Cebu City. Lahug unless noted.
            100 => [11, 1, $at(0, 30), 7, ['price' => 5_000_000, 'geo' => true]],
            101 => [11, 1, $at(-3), 7, ['price' => 3_000_000, 'status' => 'sold', 'geo' => true]],
            102 => [11, 1, $at(-10), 8, ['price' => 8_000_000]],
            103 => [11, 2, $at(-40), 8, ['price' => 30_000]],
            // 23:00 yesterday; with 110 (01:00 today) on ONE UTC date.
            104 => [11, 2, $at(0, -60), 7, ['price' => 25_000, 'visibility' => 'private']],
            105 => [11, 3, $at(-5), 9, ['price' => 2_000_000]],
            // Under the ₱100k floor: a listing, not a price.
            106 => [11, 1, $at(-2), 7, ['project' => 1, 'price' => 99_000]],
            // A project unit with no address barangay: the town's "Unknown" barangay row.
            107 => [null, 1, $at(-2, 660), 8, ['project' => 1, 'price' => 4_000_000]],
            // Cebu City project, Talisay address barangays: a "Tabunok" row of
            // its own in Cebu City, and an "Apas" folded onto Cebu City's Apas.
            108 => [12, 2, $at(-4), 7, ['project' => 3, 'price' => 18_000]],
            109 => [23, 1, $at(-6), 9, ['project' => 3, 'price' => 6_500_000]],
            126 => [11, 1, $at(-15), 8, ['price' => 2_000_000]],

            // Talisay: two `cities` rows, one town.
            110 => [12, 2, $at(0, 60), 7, ['price' => 20_000, 'geo' => true]],
            111 => [18, 1, $at(-2), 8, ['price' => 3_500_000]],
            112 => [19, 1, $at(-2), 9, ['price' => 4_500_000, 'verification' => 'flagged']],
            113 => [24, 1, $at(-8), 9, ['price' => 2_500_000]],
            114 => [25, 3, $at(-20), 7, ['price' => 1_200_000, 'status' => 'leased']],

            // Samar, filed under both of its rows.
            115 => [13, 1, $at(-2, 540), 9, ['price' => 1_800_000]],
            116 => [15, 3, $at(-2, 540), 9, ['price' => 900_000, 'status' => 'sold']],
            117 => [14, 1, $at(-2, 540), 9, ['price' => 2_200_000]],
            118 => [14, 1, $at(-1, 540), 8, ['project' => 5, 'price' => 2_600_000]],
            119 => [13, 2, $at(-3, 540), 9, ['project' => 4, 'price' => 9_000]],

            // The two Carmens.
            120 => [26, 1, $at(-2, 540), 7, ['project' => 2, 'price' => 1_500_000]],
            121 => [27, 1, $at(-3, 540), 9, ['price' => 1_700_000]],
            122 => [26, 2, $at(-5, 540), 8, ['price' => 12_000]],

            // Dinagat's town, counted under Surigao del Norte.
            123 => [16, 1, $at(-2, 540), 9, ['price' => 1_100_000]],

            // Resolves to no place at all: the id-less "Unknown" rows.
            124 => [null, 2, $at(-10, 540), 9, ['price' => 15_000]],

            // Never counted by the heatmap. All Listings shows 901-903 by default.
            900 => [11, 1, $at(-1), 7, ['listing_deleted' => true]],
            901 => [11, 1, $at(-1), 7, ['status' => 'deleted']],
            902 => [11, 1, $at(-1), 7, ['property_deleted' => true]],
            903 => [11, 4, $at(-1), 7, []],
        ];

        foreach ($rows as $id => [$barangayId, $categoryId, $createdAt, $agentId, $extra]) {
            $created = $this->utc($createdAt);
            DB::table('properties')->insert([
                'id' => $id,
                'project_id' => $extra['project'] ?? null,
                'address_id' => $barangayId,
                'property_attribute_id' => 1,
                'status' => $extra['status'] ?? 'active',
                'geo_coordinates' => ! empty($extra['geo']) ? json_encode(['lat' => 10.3, 'lng' => 123.9]) : null,
                'created_at' => $created,
                'updated_at' => $created,
                'deleted_at' => ! empty($extra['property_deleted']) ? $created : null,
            ]);
            DB::table('listings')->insert([
                'id' => $id,
                'category_id' => $categoryId,
                'property_id' => $id,
                'agent_id' => $agentId,
                'name' => "Listing {$id}",
                'price' => $extra['price'] ?? 1_000_000,
                'visibility' => $extra['visibility'] ?? 'public',
                'verification_status' => $extra['verification'] ?? null,
                'created_at' => $created,
                'updated_at' => $created,
                'deleted_at' => ! empty($extra['listing_deleted']) ? $created : null,
            ]);
        }
    }

    // ───────────────────────── the area as a set of listings ─────────────────────────

    public function test_the_fixture_exercises_every_fold_the_area_query_must_follow(): void
    {
        $heatmap = app(ListingHeatmapService::class);

        $provinces = $this->byId($heatmap->heatmap('province'));
        $this->assertSame(17, $provinces[25]['total']);
        $this->assertSame(5, $provinces[83]['total'], 'Samar: three listings filed under 82, two under 83');
        $this->assertSame(1, $provinces[73]['total'], "Dinagat's town is counted under Surigao del Norte");
        $this->assertSame(2, $provinces[12]['total'], 'Bohol holds the mislabelled Carmen project');
        $this->assertSame(0, $provinces[67]['total']);
        $this->assertSame(1, $provinces['unknown']['total']);

        $cities = $this->byId($heatmap->heatmap('city'));
        $this->assertSame(11, $cities[1]['total']);
        $this->assertSame(5, $cities[2]['total'], 'Talisay City and City of Talisay are one row, under the linked id');
        $this->assertArrayNotHasKey(8, $cities);
        $this->assertSame(3, $cities[5]['total']);
        $this->assertArrayNotHasKey(3, $cities);
        $this->assertSame(2, $cities[4]['total'], "project 5's province (83) and its town's (82) are one canonical Samar");
        // The city fold reads the LISTING's province: Bohol's Carmen holds the
        // Bohol-filed project whose town is Cebu's Carmen. A filter on
        // "the city group's ids" would say 1 and 2 here, the wrong way round —
        // which is exactly what the barangay tier, scoped by those ids, says.
        $this->assertSame(1, $cities[9]['total']);
        $this->assertSame(2, $cities[10]['total']);
        $this->assertSame(2, $heatmap->heatmap('barangay', null, null, 9)['totals']['listings']);
        $this->assertSame(1, $heatmap->heatmap('barangay', null, null, 10)['totals']['listings']);

        $cebuCity = $this->byId($heatmap->heatmap('barangay', null, null, 1));
        $this->assertSame(8, $cebuCity[11]['total']);
        $this->assertSame(1, $cebuCity[17]['total'], "Talisay's Apas (23) folds by name onto Cebu City's Apas");
        $this->assertSame(1, $cebuCity[12]['total'], "Talisay's Tabunok is a row of Cebu City's own here");
        $this->assertSame(1, $cebuCity['unknown']['total']);

        $talisay = $this->byId($heatmap->heatmap('barangay', null, null, 8));
        $this->assertSame(3, $talisay[19]['total'], 'Lagtang 18 + 19 (row 2) + 24 (row 8), under the linked 19');
        $this->assertArrayNotHasKey(18, $talisay);
        $this->assertArrayNotHasKey(24, $talisay);
        $this->assertSame(0, $talisay[23]['total'], "Talisay's own Apas: its one listing resolves to Cebu City");
    }

    public function test_every_area_row_at_every_tier_is_exactly_its_area_query(): void
    {
        $heatmap = app(ListingHeatmapService::class);
        $ranges = [
            'all time' => null,
            'the last 4 days' => $heatmap->resolveRange($this->day(-3), $this->day(0)),
            'a past window' => $heatmap->resolveRange($this->day(-12), $this->day(-8)),
            'yesterday' => $heatmap->resolveRange($this->day(-1), $this->day(-1)),
        ];
        $checked = 0;

        foreach ($ranges as $label => $range) {
            foreach (['admin' => null, 'team' => self::TEAM] as $scope => $agentIds) {
                $context = "{$label}, {$scope}";

                $checked += $this->assertEveryRowIsItsAreaQuery($heatmap->heatmap('province', null, $agentIds, null, $range), 'province', null, $agentIds, $range, $context);
                $checked += $this->assertEveryRowIsItsAreaQuery($heatmap->heatmap('city', null, $agentIds, null, $range), 'city', null, $agentIds, $range, $context);

                // A province scope narrows the rows, never a row's number.
                foreach ([12, 25, 82] as $provinceId) {
                    $checked += $this->assertEveryRowIsItsAreaQuery($heatmap->heatmap('city', $provinceId, $agentIds, null, $range), 'city', null, $agentIds, $range, "{$context}, province {$provinceId}");
                }

                foreach (self::CITIES as $cityId) {
                    $payload = $heatmap->heatmap('barangay', null, $agentIds, $cityId, $range);
                    $checked += $this->assertEveryRowIsItsAreaQuery($payload, 'barangay', $payload['city_id'], $agentIds, $range, "{$context}, city {$cityId}");
                }
            }
        }

        // Rows that are not all zero (116 today), or the loop above proved nothing.
        $this->assertGreaterThan(100, $checked);
    }

    public function test_a_twin_id_answers_for_the_row_it_folds_onto(): void
    {
        $heatmap = app(ListingHeatmapService::class);
        $provinces = $this->byId($heatmap->heatmap('province'));
        $cities = $this->byId($heatmap->heatmap('city'));
        $cebuCity = $this->byId($heatmap->heatmap('barangay', null, null, 1));
        $talisay = $this->byId($heatmap->heatmap('barangay', null, null, 2));

        // [level, the id asked for, its city, the row it is, the row's total]
        $twins = [
            ['province', 82, null, 83, $provinces[83]['total']],
            ['province', 31, null, 73, $provinces[73]['total']],
            ['city', 3, null, 5, $cities[5]['total']],
            ['city', 8, null, 2, $cities[2]['total']],
            ['barangay', 18, 2, 19, $talisay[19]['total']],
            ['barangay', 24, 8, 19, $talisay[19]['total']],
            // Talisay's Apas, asked about inside Cebu City: Cebu City's Apas row.
            ['barangay', 23, 1, 17, $cebuCity[17]['total']],
        ];

        foreach ($twins as [$level, $askedId, $cityId, $rowId, $total]) {
            $this->assertSame($rowId, $heatmap->canonicalAreaId($level, $askedId, $cityId), "{$level} {$askedId} is painted as {$rowId}");
            $this->assertSame($total, $heatmap->areaListingsQuery($level, $askedId, $cityId)->count(), "{$level} {$askedId} counts row {$rowId}");
        }
        $this->assertSame(3, $talisay[19]['total']);
    }

    public function test_a_barangay_of_another_town_is_an_empty_area_not_a_namesake(): void
    {
        $heatmap = app(ListingHeatmapService::class);

        // Talisay's Apas is not Cebu City's own, but asked about inside Talisay
        // it is Talisay's row — whose one listing resolves to Cebu City.
        $this->assertSame(0, $heatmap->areaListingsQuery('barangay', 23, 2)->count());
        // Lahug is Cebu City's. Inside Talisay it is nobody's: an empty area,
        // not whichever Talisay barangay might share a name with it.
        $this->assertSame(0, $heatmap->areaListingsQuery('barangay', 11, 2)->count());
        $this->assertSame(11, $heatmap->canonicalAreaId('barangay', 11, 2));
        // Cebu's Carmen's Poblacion is not Bohol's Carmen's.
        $this->assertSame(1, $heatmap->areaListingsQuery('barangay', 27, 10)->count());
        $this->assertSame(0, $heatmap->areaListingsQuery('barangay', 26, 10)->count());
    }

    public function test_an_id_that_names_no_place_is_an_empty_area(): void
    {
        $heatmap = app(ListingHeatmapService::class);

        // No `cities` row: no town, so no listings — not "the town with no province".
        $this->assertSame(0, $heatmap->areaListingsQuery('city', 4242)->count());
        $this->assertSame(4242, $heatmap->canonicalAreaId('city', 4242));
        $this->assertSame(0, $heatmap->areaListingsQuery('province', 4242)->count());
        $this->assertSame(0, $heatmap->areaListingsQuery('barangay', 11, 4242)->count());
        $this->assertSame(0, $this->area(['level' => 'city', 'id' => 4242])['listings']);
        $this->assertSame(0, $this->allListings(['area_level' => 'city', 'area_id' => 4242])['total']);
    }

    public function test_the_area_query_refuses_what_it_cannot_answer_exactly(): void
    {
        $heatmap = app(ListingHeatmapService::class);

        foreach ([
            'an unknown tier' => fn () => $heatmap->areaListingsQuery('region', 1),
            'a barangay with no city' => fn () => $heatmap->areaListingsQuery('barangay', 11),
            'an unknown category' => fn () => $heatmap->areaListingsQuery('city', 1, null, null, null, 'pre_selling'),
        ] as $why => $call) {
            try {
                $call();
                $this->fail("{$why} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // 'all' and null are the three categories together.
        $this->assertSame(11, $heatmap->areaListingsQuery('city', 1, null, null, null, 'all')->count());
        $this->assertSame(11, $heatmap->areaListingsQuery('city', 1)->count());
    }

    public function test_a_reused_service_carries_no_scope_from_one_area_into_the_next(): void
    {
        $heatmap = app(ListingHeatmapService::class);
        $yesterday = $heatmap->resolveRange($this->day(-1), $this->day(-1));

        $this->assertSame(1, $heatmap->areaListingsQuery('city', 1, null, self::TEAM, $yesterday, 'for_rent')->count());
        $this->assertSame(11, $heatmap->areaListingsQuery('city', 1)->count(), 'range, team and category all reset');
        $this->assertSame(26, $heatmap->heatmap('province')['totals']['listings'], 'and heatmap() starts clean after it');
    }

    // ───────────────────────── GET /listings/insights/heatmap/area ─────────────────────────

    public function test_the_area_endpoint_reports_the_rows_listings_its_agents_and_typical_prices(): void
    {
        $payload = $this->area(['level' => 'city', 'id' => 1]);

        $this->assertSame(['level', 'id', 'city_id', 'category', 'range', 'listings', 'agents', 'sale', 'rent', 'generated_at'], array_keys($payload));
        $this->assertSame('city', $payload['level']);
        $this->assertSame(1, $payload['id']);
        $this->assertNull($payload['city_id']);
        $this->assertSame('all', $payload['category']);
        $this->assertNull($payload['range']);
        $this->assertStringEndsWith('Z', $payload['generated_at']);

        $this->assertSame($this->row('city', 1)['total'], $payload['listings']);
        $this->assertSame(11, $payload['listings']);
        $this->assertSame(3, $payload['agents']);

        // For Sale: ₱2M ₱3M ₱4M ₱5M ₱6.5M ₱8M — the ₱99k listing is under the
        // floor. Even, so the middle two average.
        $this->assertEquals(['median' => 4_500_000, 'count' => 6], $payload['sale']);
        // For Rent: ₱18k and ₱30k — the private ₱25k one is not a public price.
        $this->assertEquals(['median' => 24_000, 'count' => 2], $payload['rent']);
    }

    public function test_the_area_endpoint_under_a_category_is_that_categorys_listings_only(): void
    {
        $sale = $this->area(['level' => 'city', 'id' => 1, 'category' => 'for_sale']);
        $this->assertSame('for_sale', $sale['category']);
        $this->assertSame($this->row('city', 1)['by_category']['for_sale']['total'], $sale['listings']);
        $this->assertSame(7, $sale['listings']);
        $this->assertSame(3, $sale['agents']);
        $this->assertEquals(['median' => 4_500_000, 'count' => 6], $sale['sale']);
        $this->assertNull($sale['rent'], 'no For Rent listing is a For Sale listing');

        $rent = $this->area(['level' => 'city', 'id' => 1, 'category' => 'for_rent']);
        $this->assertSame(3, $rent['listings']);
        $this->assertSame(2, $rent['agents']);
        $this->assertNull($rent['sale']);
        $this->assertEquals(['median' => 24_000, 'count' => 2], $rent['rent']);

        $foreclosure = $this->area(['level' => 'city', 'id' => 1, 'category' => 'foreclosure']);
        $this->assertSame(1, $foreclosure['listings']);
        $this->assertSame(1, $foreclosure['agents']);
        $this->assertNull($foreclosure['sale']);
        $this->assertNull($foreclosure['rent']);

        $this->assertSame(11, $this->area(['level' => 'city', 'id' => 1, 'category' => 'all'])['listings']);
    }

    public function test_the_area_endpoint_at_province_and_barangay_level(): void
    {
        $samar = $this->area(['level' => 'province', 'id' => 82]);
        $this->assertSame(83, $samar['id'], 'echoes the canonical id');
        $this->assertSame(5, $samar['listings']);
        $this->assertSame(2, $samar['agents']);
        $this->assertEquals(['median' => 2_200_000, 'count' => 3], $samar['sale']);
        $this->assertEquals(['median' => 9_000, 'count' => 1], $samar['rent']);

        $lagtang = $this->area(['level' => 'barangay', 'id' => 18, 'city_id' => 8]);
        $this->assertSame(19, $lagtang['id']);
        $this->assertSame(2, $lagtang['city_id'], "the city group's canonical id");
        $this->assertSame($this->row('barangay', 19, 2)['total'], $lagtang['listings']);
        $this->assertSame(3, $lagtang['listings']);
        $this->assertSame(2, $lagtang['agents']);
        // ₱2.5M and ₱3.5M; the flagged ₱4.5M listing is not a price anyone trusts.
        $this->assertEquals(['median' => 3_000_000, 'count' => 2], $lagtang['sale']);
        $this->assertNull($lagtang['rent']);

        $apas = $this->area(['level' => 'barangay', 'id' => 17, 'city_id' => 1]);
        $this->assertSame(1, $apas['listings']);
        $this->assertEquals(['median' => 6_500_000, 'count' => 1], $apas['sale']);
    }

    public function test_the_area_endpoint_under_a_range_and_for_a_team_leader(): void
    {
        $range = ['date_start' => $this->day(-3), 'date_end' => $this->day(30)];

        $ranged = $this->area(['level' => 'city', 'id' => 1] + $range);
        $this->assertSame(['date_start' => $this->day(-3), 'date_end' => $this->day(0)], $ranged['range'], 'clamped to today');
        $this->assertSame($this->row('city', 1, null, null, ['date_start' => $this->day(-3), 'date_end' => $this->day(0)])['total'], $ranged['listings']);
        $this->assertSame(5, $ranged['listings']);

        $leader = $this->area(['level' => 'city', 'id' => 1], 'agent', 700);
        $this->assertSame($this->row('city', 1, null, self::TEAM)['total'], $leader['listings']);
        $this->assertSame(9, $leader['listings']);
        $this->assertSame(2, $leader['agents']);
        // ₱2M ₱3M ₱4M ₱5M ₱8M: agent 9's ₱6.5M is not the team's.
        $this->assertEquals(['median' => 4_000_000, 'count' => 5], $leader['sale']);
    }

    public function test_the_area_endpoint_validates_like_the_counts_endpoint(): void
    {
        $cases = [
            'no level' => [['id' => 1], 'level'],
            'an unknown level' => [['level' => 'region', 'id' => 1], 'level'],
            'no id' => [['level' => 'city'], 'id'],
            'a zero id' => [['level' => 'city', 'id' => 0], 'id'],
            'a barangay with no city' => [['level' => 'barangay', 'id' => 11], 'city_id'],
            'an end before the start' => [['level' => 'city', 'id' => 1, 'date_start' => $this->day(-1), 'date_end' => $this->day(-2)], 'date_end'],
            'a lone date_start' => [['level' => 'city', 'id' => 1, 'date_start' => $this->day(-1)], 'date_end'],
            'a day before EARLIEST_DAY' => [['level' => 'city', 'id' => 1, 'date_start' => '1999-12-31', 'date_end' => '2000-01-05'], 'date_start'],
            'an unknown category' => [['level' => 'city', 'id' => 1, 'category' => 'For Sale'], 'category'],
        ];

        foreach ($cases as $why => [$query, $field]) {
            try {
                $this->area($query);
                $this->fail("{$why} must be a 422");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), "{$why} is reported against {$field}");
            }
        }
    }

    public function test_a_user_who_leads_nobody_is_refused_area_details(): void
    {
        $this->expectException(HttpException::class);

        $this->area(['level' => 'city', 'id' => 1], 'agent', 800);
    }

    public function test_area_details_are_cached_per_canonical_area_range_category_and_scope(): void
    {
        $first = $this->area(['level' => 'province', 'id' => 83]);

        $listingQueries = [];
        DB::listen(function ($query) use (&$listingQueries) {
            if (str_contains($query->sql, 'listings')) {
                $listingQueries[] = $query->sql;
            }
        });

        // The twin id is the same entry, and a future end is today's entry.
        $this->assertSame($first, $this->area(['level' => 'province', 'id' => 82]));
        $this->area(['level' => 'barangay', 'id' => 19, 'city_id' => 2]);
        $listingQueries = [];
        $this->area(['level' => 'barangay', 'id' => 24, 'city_id' => 8]);
        $this->area(['level' => 'barangay', 'id' => 18, 'city_id' => 2, 'category' => '']);
        $this->assertSame([], $listingQueries, 'Lagtang 18, 19 and 24 are one entry');

        $this->area(['level' => 'city', 'id' => 1, 'date_start' => $this->day(-3), 'date_end' => $this->day(0)]);
        $listingQueries = [];
        $this->area(['level' => 'city', 'id' => 1, 'date_start' => $this->day(-3), 'date_end' => $this->day(9)]);
        $this->assertSame([], $listingQueries, 'a clamped range shares the entry of the range it was clamped to');

        // Different questions are different entries.
        foreach ([
            ['level' => 'city', 'id' => 1, 'category' => 'for_rent'],
            ['level' => 'city', 'id' => 1, 'date_start' => $this->day(-1), 'date_end' => $this->day(-1)],
        ] as $query) {
            $listingQueries = [];
            $this->area($query);
            $this->assertNotSame([], $listingQueries, json_encode($query).' must be computed, not read back');
        }

        $admin = $this->area(['level' => 'city', 'id' => 2]);
        $leader = $this->area(['level' => 'city', 'id' => 2], 'agent', 700);
        $this->assertSame(5, $admin['listings']);
        $this->assertSame(3, $leader['listings'], 'a leader is never served the admin entry');

        // A boundaries re-import or re-link is a new generation of entries.
        Cache::forever('heatmap:boundaries:ver', 2);
        $listingQueries = [];
        $this->area(['level' => 'province', 'id' => 83]);
        $this->assertNotSame([], $listingQueries);
    }

    // ───────────────────────── GET /all-listings ─────────────────────────

    public function test_all_listings_shows_exactly_the_listings_of_every_heatmap_row(): void
    {
        $heatmap = app(ListingHeatmapService::class);
        $checked = 0;

        foreach ([null, ['date_start' => $this->day(-6), 'date_end' => $this->day(0)], ['date_start' => $this->day(-12), 'date_end' => $this->day(-2)]] as $dates) {
            $range = $dates !== null ? $heatmap->resolveRange($dates['date_start'], $dates['date_end']) : null;
            $added = $dates !== null ? ['added_from' => $dates['date_start'], 'added_to' => $dates['date_end']] : [];

            $tiers = [['province', $heatmap->heatmap('province', null, null, null, $range)], ['city', $heatmap->heatmap('city', null, null, null, $range)]];
            foreach (self::CITIES as $cityId) {
                $tiers[] = ['barangay', $heatmap->heatmap('barangay', null, null, $cityId, $range)];
            }

            foreach ($tiers as [$level, $payload]) {
                foreach ($payload['data'] as $row) {
                    if ($row['id'] === null) {
                        continue;
                    }
                    $link = ['area_level' => $level, 'area_id' => $row['id']]
                        + ($level === 'barangay' ? ['area_city_id' => $payload['city_id']] : [])
                        + $added;

                    $this->assertSame($row['total'], $this->allListings($link)['total'], "{$level} {$row['name']} ".json_encode($dates));
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(60, $checked);
    }

    public function test_all_listings_parity_holds_per_category_and_per_status_with_the_pages_own_params(): void
    {
        foreach ([['province', 25, null], ['province', 83, null], ['city', 1, null], ['city', 2, null], ['city', 10, null], ['barangay', 19, 2], ['barangay', 11, 1]] as [$level, $id, $cityId]) {
            $row = $this->row($level, $id, $cityId);
            $link = ['area_level' => $level, 'area_id' => $id] + ($cityId !== null ? ['area_city_id' => $cityId] : []);

            // The page's category dropdown sends the category's NAME.
            foreach (self::CATEGORY_NAMES as $key => $name) {
                $this->assertSame($row['by_category'][$key]['total'], $this->allListings($link + ['category' => $name])['total'], "{$level} {$id} {$name}");
            }
            // The heatmap's status counts are the property's status, as is All Listings' status filter.
            foreach (['sold', 'rented', 'leased'] as $status) {
                $this->assertSame($row[$status], $this->allListings($link + ['status' => $status])['total'], "{$level} {$id} {$status}");
            }
        }

        $this->assertSame(1, $this->allListings(['area_level' => 'city', 'area_id' => 1, 'status' => 'sold'])['total']);
        $this->assertSame(1, $this->allListings(['area_level' => 'city', 'area_id' => 2, 'status' => 'leased'])['total']);
    }

    public function test_all_listings_parity_for_a_team_leader_through_all_listings_own_scope(): void
    {
        foreach ([['province', 25, null], ['city', 1, null], ['city', 2, null], ['barangay', 19, 2]] as [$level, $id, $cityId]) {
            $link = ['area_level' => $level, 'area_id' => $id] + ($cityId !== null ? ['area_city_id' => $cityId] : []);

            $this->assertSame(
                $this->row($level, $id, $cityId, self::TEAM)['total'],
                $this->allListings($link, 'agent', 700)['total'],
                "{$level} {$id}: the team's heatmap row is the team's list"
            );
        }
        $this->assertSame(9, $this->allListings(['area_level' => 'city', 'area_id' => 1], 'agent', 700)['total']);
    }

    public function test_all_listings_stat_cards_count_the_area_too(): void
    {
        $counts = $this->allListings(['area_level' => 'city', 'area_id' => 1])['counts'];

        $this->assertSame(['active' => 10, 'rented' => 0, 'sold' => 1, 'leased' => 0], $counts['status']);
        $this->assertSame(10, $counts['visibility']['public']);
        $this->assertSame(1, $counts['visibility']['private']);
        $this->assertSame(11, $counts['verification']['unverified']);
    }

    public function test_added_dates_alone_are_manila_days_and_date_from_is_untouched(): void
    {
        $yesterday = ['added_from' => $this->day(-1), 'added_to' => $this->day(-1)];

        // 23:00 yesterday (104), 09:00 yesterday (118) and the three rows only
        // All Listings counts (901-903; 900 is trashed). The 00:30 and 01:00
        // listings of today share yesterday's UTC date and must stay out.
        $this->assertSame(5, $this->allListings($yesterday)['total']);
        $this->assertSame(1, $this->allListings($yesterday + ['area_level' => 'province', 'area_id' => 25])['total'], 'inside Cebu: only 104');

        // The audit queue's date_from / date_to still read UTC days, as before.
        $this->assertSame(7, $this->allListings(['date_from' => $this->day(-1), 'date_to' => $this->day(-1)])['total']);

        // A future end is clamped, never refused, exactly as on the map.
        $this->assertSame(
            $this->allListings(['added_from' => $this->day(-3), 'added_to' => $this->day(0)])['total'],
            $this->allListings(['added_from' => $this->day(-3), 'added_to' => $this->day(30)])['total']
        );
    }

    public function test_all_listings_without_the_new_params_is_unchanged(): void
    {
        // Everything not trashed, including the rows the heatmap never counts.
        $this->assertSame(29, $this->allListings([])['total']);
        $this->assertSame(29, $this->allListings(['area_level' => '', 'area_id' => '', 'added_from' => '', 'added_to' => ''])['total']);

        // The heatmap's area holds no trashed listing, so "deleted" views are empty under it.
        $this->assertSame(0, $this->allListings(['area_level' => 'city', 'area_id' => 1, 'trashed' => '1'])['total']);
    }

    public function test_all_listings_area_params_are_all_or_none(): void
    {
        $cases = [
            'a level and no id' => [['area_level' => 'city'], 'area_id'],
            'an id and no level' => [['area_id' => 1], 'area_level'],
            'a city and nothing else' => [['area_city_id' => 1], 'area_level'],
            'a barangay with no city' => [['area_level' => 'barangay', 'area_id' => 11], 'area_city_id'],
            'an unknown level' => [['area_level' => 'region', 'area_id' => 1], 'area_level'],
            'a zero id' => [['area_level' => 'city', 'area_id' => 0], 'area_id'],
            'a lone added_from' => [['added_from' => $this->day(-1)], 'added_to'],
            'an end before the start' => [['added_from' => $this->day(-1), 'added_to' => $this->day(-2)], 'added_to'],
            'a US-style date' => [['added_from' => '10/01/2026', 'added_to' => '2026-10-31'], 'added_from'],
        ];

        foreach ($cases as $why => [$query, $field]) {
            try {
                $this->allListings($query);
                $this->fail("{$why} must be a 422");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), "{$why} is reported against {$field}");
            }
        }
    }

    public function test_map_markers_follow_the_area(): void
    {
        $this->assertSame(3, $this->mapMarkers([])['total']);
        $this->assertSame(2, $this->mapMarkers(['area_level' => 'city', 'area_id' => 1])['total']);
        $this->assertSame(1, $this->mapMarkers(['area_level' => 'barangay', 'area_id' => 12, 'area_city_id' => 2])['total']);
        $this->assertSame(1, $this->mapMarkers(['area_level' => 'city', 'area_id' => 1, 'added_from' => $this->day(0), 'added_to' => $this->day(0)])['total']);
        $this->assertSame(0, $this->mapMarkers(['area_level' => 'province', 'area_id' => 83])['total']);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Every row with an id: its total, and each category's, against the area
     * query. Returns how many rows held at least one listing.
     */
    private function assertEveryRowIsItsAreaQuery(array $payload, string $level, ?int $cityId, ?array $agentIds, ?array $range, string $context): int
    {
        // A fresh instance: the area query must not depend on what heatmap() left behind.
        $heatmap = app(ListingHeatmapService::class);
        $nonEmpty = 0;

        foreach ($payload['data'] as $row) {
            if ($row['id'] === null) {
                continue;
            }
            $where = "{$context}: {$level} {$row['name']} ({$row['id']})";

            $this->assertSame($row['total'], $heatmap->areaListingsQuery($level, $row['id'], $cityId, $agentIds, $range)->count(), $where);
            foreach (array_keys(self::CATEGORY_NAMES) as $category) {
                $this->assertSame(
                    $row['by_category'][$category]['total'],
                    $heatmap->areaListingsQuery($level, $row['id'], $cityId, $agentIds, $range, $category)->count(),
                    "{$where} {$category}"
                );
            }

            $nonEmpty += $row['total'] > 0 ? 1 : 0;
        }

        return $nonEmpty;
    }

    /** One heatmap row, by id, as the counts service returns it. */
    private function row(string $level, int $id, ?int $cityId = null, ?array $agentIds = null, ?array $dates = null): array
    {
        $heatmap = app(ListingHeatmapService::class);
        $range = $dates !== null ? $heatmap->resolveRange($dates['date_start'], $dates['date_end']) : null;
        $rows = $this->byId($heatmap->heatmap($level, null, $agentIds, $cityId, $range));

        $this->assertArrayHasKey($id, $rows, "{$level} {$id} is a row");

        return $rows[$id];
    }

    /** Rows keyed by area id ('unknown' for the id-less row). */
    private function byId(array $payload): array
    {
        $rows = [];
        foreach ($payload['data'] as $row) {
            $rows[$row['id'] ?? 'unknown'] = $row;
        }

        return $rows;
    }

    private function user(string $role, int $id): User
    {
        $user = new User;
        $user->id = $id;
        $user->setRelation('role', (new Role)->forceFill(['name' => $role]));

        return $user;
    }

    /** GET /listings/insights/heatmap/area, the way the route would call it. */
    private function area(array $query, string $role = 'admin', int $userId = 1): array
    {
        $request = Request::create('/api/listings/insights/heatmap/area', 'GET', $query);
        $request->setUserResolver(fn () => $this->user($role, $userId));

        return app(ListingController::class)->insightsHeatmapArea($request, app(ListingHeatmapService::class))->getData(true);
    }

    /**
     * GET /all-listings. `total` is the paginator's — what the response sends
     * as meta.total — read before the resources render (they need tables this
     * fixture does not build).
     *
     * @return array{total: int, counts: ?array}
     */
    private function allListings(array $query, string $role = 'admin', int $userId = 1): array
    {
        $request = Request::create('/api/all-listings', 'GET', $query);
        $request->setUserResolver(fn () => $this->user($role, $userId));

        $collection = app(ListingController::class)->allListings($request);

        return ['total' => $collection->resource->total(), 'counts' => $collection->additional['counts'] ?? null];
    }

    /** GET /all-listings/map-markers. */
    private function mapMarkers(array $query): array
    {
        $request = Request::create('/api/all-listings/map-markers', 'GET', $query);
        $request->setUserResolver(fn () => $this->user('admin', 1));

        return app(ListingController::class)->mapMarkers($request)->getData(true);
    }

    /** created_at as the UTC literal the column stores, from a Manila instant. */
    private function utc(Carbon $manila): string
    {
        return $manila->copy()->utc()->format('Y-m-d H:i:s');
    }

    /** A Manila calendar day as Y-m-d, $offset days from today. */
    private function day(int $offset): string
    {
        return $this->manilaMidnight->copy()->addDays($offset)->format('Y-m-d');
    }
}
