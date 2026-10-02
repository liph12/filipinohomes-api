<?php

namespace Tests\Feature;

use App\Console\Commands\RelinkBarangayBoundaries;
use App\Support\BarangayGroupPlacement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/**
 * boundaries:relink-barangays — placing each town by vote + name, then its
 * barangays by name, and writing exactly that.
 *
 * The ONLY spatial SQL in the command is the centroid vote, isolated in one
 * overridable method precisely so this test can inject the vote rows and run
 * the decision rules, the claim semantics and the write path on sqlite. What
 * is worth testing here is not MySQL's ST_Contains but what the command does
 * with its answer: an exact name must beat a sliver vote, a near-miss name
 * must not, twin city rows must count as one town, a same-name registry twin
 * must resolve to the row with listings, fan-in must not trip the over-link
 * check, a dry run must write nothing, and a real run must wipe stale links.
 */
class RelinkBarangayBoundariesTest extends TestCase
{
    /** parent_psgc => vote rows the fake "spatial" query returns. */
    private array $votes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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
            $table->unsignedBigInteger('city_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('property_id');
            $table->timestamps();
            $table->softDeletes();
        });
        // No `geom`: sqlite has no spatial type and the command only touches
        // geometry inside the vote query this test replaces.
        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->string('level');
            $table->string('name');
            $table->string('parent_name')->nullable();
            $table->string('grandparent_name')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->string('psgc_code', 16)->nullable();
            $table->string('parent_psgc', 16)->nullable();
            $table->string('link_how', 16)->nullable();
            $table->timestamps();
        });

        $this->seedFixture();
    }

    /**
     * One province with every placement case, plus Samar's duplicate province
     * rows so the twin-town rule is exercised:
     *
     *   Cebu City       vote 448 + exact name 448            → geo+name
     *   City of Talisay vote says Naga (sliver), exact name  → name-wins
     *   San Remegio     vote says Bogo, name is a near-miss  → geo-wins
     *   Unknown Town    vote only                            → geo-only
     *   Carcar City     name only                            → name-only
     *   Nowhere         neither                              → NULL
     *   Catbalogan      vote → twin row 5, name → twin row 3 → geo+name on 3
     */
    private function seedFixture(): void
    {
        DB::table('provinces')->insert([
            ['id' => 25, 'name' => 'Cebu'],
            ['id' => 82, 'name' => 'Southern Samar'],
            ['id' => 83, 'name' => 'Samar'],
        ]);
        DB::table('cities')->insert([
            ['id' => 448, 'name' => 'Cebu City', 'province_id' => 25],
            ['id' => 449, 'name' => 'Talisay City', 'province_id' => 25],
            ['id' => 450, 'name' => 'Naga', 'province_id' => 25],
            ['id' => 451, 'name' => 'San Remigio', 'province_id' => 25],
            ['id' => 452, 'name' => 'Bogo City', 'province_id' => 25],
            ['id' => 453, 'name' => 'Liloan', 'province_id' => 25],
            ['id' => 454, 'name' => 'Carcar City', 'province_id' => 25],
            ['id' => 3, 'name' => 'Catbalogan', 'province_id' => 83],
            ['id' => 5, 'name' => 'Catbalogan', 'province_id' => 82],
        ]);
        DB::table('barangays')->insert([
            ['id' => 1, 'name' => 'Lahug', 'city_id' => 448],
            ['id' => 2, 'name' => 'Mabolo', 'city_id' => 448],
            ['id' => 3, 'name' => 'Guadalupe', 'city_id' => 448],
            ['id' => 4, 'name' => 'Banawa', 'city_id' => 448],   // a sitio, no PSA polygon
            ['id' => 5, 'name' => 'Sambag 1', 'city_id' => 448],
            ['id' => 6, 'name' => 'Sambag 2', 'city_id' => 448],
            ['id' => 7, 'name' => 'Fatima', 'city_id' => 448],   // the file has Fatima I and II
            ['id' => 101, 'name' => 'Lagtang', 'city_id' => 449],   // carries the listings
            ['id' => 102, 'name' => 'Lagtang', 'city_id' => 449],   // empty twin
            ['id' => 103, 'name' => 'Dumlog', 'city_id' => 449],
            ['id' => 201, 'name' => 'Tinaan', 'city_id' => 450],
            ['id' => 301, 'name' => 'Looc', 'city_id' => 451],
            ['id' => 401, 'name' => 'Gairan', 'city_id' => 452],
            ['id' => 501, 'name' => 'Yati', 'city_id' => 453],
            ['id' => 601, 'name' => 'Valladolid', 'city_id' => 454],
            ['id' => 701, 'name' => 'Mercedes', 'city_id' => 3],
            ['id' => 702, 'name' => 'Payao', 'city_id' => 5],   // only on the twin row
        ]);
        DB::table('categories')->insert([['id' => 1, 'name' => 'For Sale']]);

        $listings = [1 => 1, 4 => 5, 101 => 3, 701 => 2];
        $propertyId = 1;
        foreach ($listings as $barangayId => $n) {
            for ($i = 0; $i < $n; $i++) {
                DB::table('properties')->insert(['id' => $propertyId, 'address_id' => $barangayId]);
                DB::table('listings')->insert(['id' => $propertyId, 'category_id' => 1, 'property_id' => $propertyId]);
                $propertyId++;
            }
        }

        // Linked city polygons: the guard needs at least one, the (stubbed)
        // vote conceptually reads them.
        foreach ([448, 449, 450, 452, 453, 5] as $cityId) {
            DB::table('boundaries')->insert(['level' => 'city', 'name' => 'c'.$cityId, 'city_id' => $cityId, 'province_id' => 25]);
        }

        $this->polygons('PH0702217', 'Cebu City', 'Cebu', [
            'Lahug (Pob.)', 'Mabolo', 'Guadalupe', 'Sambag I', 'Sambag II', 'Fatima I', 'Fatima II', 'Kalubihan (Pob.)',
        ]);
        $this->polygons('PH0702248', 'City of Talisay', 'Cebu', ['Lagtang', 'Dumlog']);
        $this->polygons('PH0702240', 'San Remegio', 'Cebu', ['Looc']);
        $this->polygons('PH0702299', 'Unknown Town', 'Cebu', ['Yati']);
        $this->polygons('PH0702212', 'Carcar City', 'Cebu', ['Valladolid']);
        $this->polygons('PH0702298', 'Nowhere', 'Cebu', ['Lost']);
        $this->polygons('PH0806005', 'City of Catbalogan', 'Samar (Western Samar)', ['Mercedes', 'Payao']);

        // A stale link from an earlier run, on a town that now places nowhere.
        DB::table('boundaries')->where('psgc_code', 'PH0702298001')
            ->update(['city_id' => 454, 'province_id' => 25, 'barangay_id' => 601, 'link_how' => 'geo+name']);

        $this->votes = [
            'PH0702217' => [['city_id' => 448, 'province_id' => 25, 'votes' => 8]],
            'PH0702248' => [['city_id' => 450, 'province_id' => 25, 'votes' => 2]],
            'PH0702240' => [['city_id' => 452, 'province_id' => 25, 'votes' => 1]],
            'PH0702299' => [['city_id' => 453, 'province_id' => 25, 'votes' => 1]],
            'PH0806005' => [['city_id' => 5, 'province_id' => 83, 'votes' => 2]],
        ];
    }

    /** @param  string[]  $names */
    private function polygons(string $parentPsgc, string $town, string $province, array $names): void
    {
        foreach ($names as $i => $name) {
            DB::table('boundaries')->insert([
                'level' => 'barangay',
                'name' => $name,
                'parent_name' => $town,
                'grandparent_name' => $province,
                'psgc_code' => $parentPsgc.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'parent_psgc' => $parentPsgc,
            ]);
        }
    }

    /** @return array{0: int, 1: string} exit code and console output */
    private function relink(array $options = []): array
    {
        $command = new FakeRelinkBarangayBoundaries($this->votes);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exit = $tester->execute($options);

        return [$exit, $tester->getDisplay()];
    }

    private function row(string $psgcCode): object
    {
        return DB::table('boundaries')->where('psgc_code', $psgcCode)->first();
    }

    // ───────────────────────── the pure decision rules ─────────────────────────

    public function test_majority_takes_the_most_votes_and_breaks_a_tie_on_the_lower_city_id(): void
    {
        $this->assertNull(BarangayGroupPlacement::majority([]));

        $this->assertSame(
            ['city_id' => 7, 'province_id' => 25, 'votes' => 5, 'total' => 8],
            BarangayGroupPlacement::majority([
                ['city_id' => 9, 'province_id' => 25, 'votes' => 3],
                ['city_id' => 7, 'province_id' => 25, 'votes' => 5],
            ])
        );

        // Tie: the lower id, whatever the row order; stdClass rows accepted.
        $tie = BarangayGroupPlacement::majority([
            (object) ['city_id' => '9', 'province_id' => '25', 'votes' => '4'],
            (object) ['city_id' => '7', 'province_id' => null, 'votes' => '4'],
        ]);
        $this->assertSame(7, $tie['city_id']);
        $this->assertNull($tie['province_id']);
        $this->assertSame(8, $tie['total']);
    }

    public function test_decide_covers_every_combination_of_the_two_signals(): void
    {
        $decide = fn (...$args) => BarangayGroupPlacement::decide(...$args);

        $this->assertSame(['city_id' => null, 'how' => null], $decide(null, null, false));
        $this->assertSame(['city_id' => 5, 'how' => 'geo-only'], $decide(5, null, false));
        $this->assertSame(['city_id' => 6, 'how' => 'name-only'], $decide(null, 6, true));
        $this->assertSame(['city_id' => 6, 'how' => 'name-only'], $decide(null, 6, false));
        $this->assertSame(['city_id' => 5, 'how' => 'geo+name'], $decide(5, 5, true));
        $this->assertSame(['city_id' => 5, 'how' => 'geo+name'], $decide(5, 5, false));
        // Disagreement: an exact name beats the vote, a near-miss does not.
        $this->assertSame(['city_id' => 6, 'how' => 'name-wins'], $decide(5, 6, true));
        $this->assertSame(['city_id' => 5, 'how' => 'geo-wins'], $decide(5, 6, false));
        // Twin rows of one town are not a disagreement; the name's row is kept.
        $this->assertSame(['city_id' => 6, 'how' => 'geo+name'], $decide(5, 6, false, true));

        $this->assertSame(['geo+name', 'name-wins', 'geo-wins', 'geo-only', 'name-only'], BarangayGroupPlacement::HOWS);
    }

    // ───────────────────────────── the command ─────────────────────────────

    public function test_it_places_every_town_by_the_merged_rules_and_links_its_barangays(): void
    {
        [$exit, $output] = $this->relink();

        $this->assertSame(0, $exit, $output);

        // geo+name
        $lahug = $this->row('PH0702217001');
        $this->assertSame([448, 25, 'geo+name', 1], [(int) $lahug->city_id, (int) $lahug->province_id, $lahug->link_how, (int) $lahug->barangay_id]);
        // name-wins: the sliver vote for Naga loses to the exact in-province name.
        $lagtang = $this->row('PH0702248001');
        $this->assertSame([449, 'name-wins'], [(int) $lagtang->city_id, $lagtang->link_how]);
        // geo-wins: "San Remegio" is only a near-miss of San Remigio, so Bogo's vote stands…
        $looc = $this->row('PH0702240001');
        $this->assertSame([452, 'geo-wins'], [(int) $looc->city_id, $looc->link_how]);
        // …and Bogo's registry has no Looc, so the polygon stays unmatched rather than crossing into San Remigio.
        $this->assertNull($looc->barangay_id);
        // geo-only
        $yati = $this->row('PH0702299001');
        $this->assertSame([453, 'geo-only', 501], [(int) $yati->city_id, $yati->link_how, (int) $yati->barangay_id]);
        // name-only
        $valladolid = $this->row('PH0702212001');
        $this->assertSame([454, 25, 'name-only', 601], [(int) $valladolid->city_id, (int) $valladolid->province_id, $valladolid->link_how, (int) $valladolid->barangay_id]);
        // neither: the stale link from the earlier run is gone.
        $lost = $this->row('PH0702298001');
        $this->assertSame([null, null, null, null], [$lost->city_id, $lost->province_id, $lost->barangay_id, $lost->link_how]);

        $this->assertStringContainsString('geo+name', $output);
        $this->assertStringContainsString('City of Talisay', $output);
        $this->assertStringContainsString('kept: name', $output);
        $this->assertStringContainsString('kept: vote', $output);
    }

    public function test_twin_city_rows_are_one_town_so_the_vote_and_the_name_agree_and_both_registries_serve(): void
    {
        [$exit] = $this->relink();
        $this->assertSame(0, $exit);

        // Vote said row 5 (Southern Samar), name said row 3 (Samar): same town,
        // no disagreement, the listing-bearing row the name path chose is kept.
        $mercedes = $this->row('PH0806005001');
        $this->assertSame([3, 83, 'geo+name', 701], [(int) $mercedes->city_id, (int) $mercedes->province_id, $mercedes->link_how, (int) $mercedes->barangay_id]);

        // Payao exists only on the twin row's registry and is still found.
        $payao = $this->row('PH0806005002');
        $this->assertSame([3, 702], [(int) $payao->city_id, (int) $payao->barangay_id]);
    }

    public function test_barangay_claims_prefer_the_listing_bearing_twin_and_fan_in_is_not_an_over_link(): void
    {
        [$exit, $output] = $this->relink();
        $this->assertSame(0, $exit);

        // Same-name registry twins: the row with listings wins; the empty twin stays unlinked.
        $this->assertSame(101, (int) $this->row('PH0702248001')->barangay_id);
        $this->assertSame(0, DB::table('boundaries')->where('barangay_id', 102)->count());

        // Roman numerals are digits: Sambag I / II land on their own rows, never on each other.
        $this->assertSame(5, (int) $this->row('PH0702217004')->barangay_id);
        $this->assertSame(6, (int) $this->row('PH0702217005')->barangay_id);

        // Fan-in: both Fatima polygons share the one "Fatima" row, by design.
        $this->assertSame(7, (int) $this->row('PH0702217006')->barangay_id);
        $this->assertSame(7, (int) $this->row('PH0702217007')->barangay_id);

        // A polygon with no registry row stays NULL (rendered grey, never a wrong barangay).
        $this->assertNull($this->row('PH0702217008')->barangay_id);

        $this->assertStringContainsString('over-link (a registry row claimed by more than one polygon outside fan-in): 0  ✓', $output);
        $this->assertStringContainsString("cross-city (registry city is not the polygon's town): 0  ✓", $output);
        $this->assertStringContainsString('fan-in shares (allowed many-to-one): 2 polygons → 1 registry rows', $output);

        // The fixed Cebu City line, and Banawa as the first listing-bearing gap.
        $this->assertStringContainsString('Cebu City (city 448): 8 polygons (7 linked), 6 linked of 7 registry rows; registry rows without a polygon: Banawa (5 listings)', $output);
        $this->assertMatchesRegularExpression('/NO polygon \(1; 5 listings.*\n\s+5  Banawa/', $output);
        // Coverage: 11 listings on registry barangays, 6 of them (Lahug 1, Lagtang 3, Mercedes 2) covered.
        $this->assertStringContainsString('6 of 11  =  54.55%', $output);
    }

    public function test_dry_run_prints_the_same_report_and_writes_nothing(): void
    {
        Cache::forever('heatmap:boundaries:ver', 7);
        $before = DB::table('boundaries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        [$exit, $output] = $this->relink(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('Cebu City (city 448): 8 polygons (7 linked)', $output);
        $this->assertSame($before, DB::table('boundaries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(7, Cache::get('heatmap:boundaries:ver'));

        // The real run bumps the geometry version so the map refetches.
        [$exit] = $this->relink();
        $this->assertSame(0, $exit);
        $this->assertSame(8, Cache::get('heatmap:boundaries:ver'));
    }

    public function test_city_scope_writes_only_that_town_and_leaves_the_rest_untouched(): void
    {
        // A stale link on a Cebu City polygon that a full run would correct.
        DB::table('boundaries')->where('psgc_code', 'PH0702217002')->update(['city_id' => 448, 'barangay_id' => 999]);

        [$exit, $output] = $this->relink(['--city' => '449']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Scoped to city Talisay City', $output);

        $this->assertSame([449, 'name-wins', 101], [(int) $this->row('PH0702248001')->city_id, $this->row('PH0702248001')->link_how, (int) $this->row('PH0702248001')->barangay_id]);
        $this->assertSame(103, (int) $this->row('PH0702248002')->barangay_id);

        // Out of scope: the stale Cebu City link survives, Nowhere's stale link survives.
        $this->assertSame(999, (int) $this->row('PH0702217002')->barangay_id);
        $this->assertSame(601, (int) $this->row('PH0702298001')->barangay_id);
        $this->assertNull($this->row('PH0702217001')->city_id);

        [$exit, $output] = $this->relink(['--city' => '99999']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('is not a cities.id', $output);
    }

    public function test_report_option_writes_the_text_and_the_two_json_side_files(): void
    {
        $dir = sys_get_temp_dir().'/relink-bgy-'.uniqid();
        $path = $dir.'/relink.txt';

        try {
            [$exit] = $this->relink(['--dry-run' => true, '--report' => $path]);
            $this->assertSame(0, $exit);

            $this->assertFileExists($path);
            $this->assertStringContainsString('boundaries:relink-barangays — DRY RUN', (string) file_get_contents($path));

            $links = json_decode((string) file_get_contents($path.'.links.json'), true);
            $this->assertSame(1, $links['PH0702217001']);
            $this->assertSame(7, $links['PH0702217006']);
            $this->assertNull($links['PH0702217008']);
            $this->assertNull($links['PH0702298001']);
            $this->assertCount(16, $links);

            $towns = json_decode((string) file_get_contents($path.'.groups.json'), true);
            $this->assertSame(448, $towns['PH0702217']);
            $this->assertSame(449, $towns['PH0702248']);
            $this->assertSame(452, $towns['PH0702240']);
            $this->assertNull($towns['PH0702298']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function test_it_refuses_to_run_without_barangay_rows_linked_city_polygons_or_parent_codes(): void
    {
        DB::table('boundaries')->where('level', 'city')->update(['city_id' => null]);
        [$exit, $output] = $this->relink();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No LINKED city polygons', $output);
        DB::table('boundaries')->where('level', 'city')->update(['city_id' => 448]);

        DB::table('boundaries')->where('psgc_code', 'PH0702217001')->update(['parent_psgc' => null]);
        [$exit, $output] = $this->relink();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('have no parent_psgc', $output);

        DB::table('boundaries')->where('level', 'barangay')->delete();
        [$exit, $output] = $this->relink();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No barangay polygons', $output);

        // Nothing was written by any refused run.
        $this->assertSame(0, DB::table('boundaries')->whereNotNull('link_how')->count());
    }

    public function test_a_failing_vote_chunk_is_retried_per_town_and_reported_not_fatal(): void
    {
        $command = new FakeRelinkBarangayBoundaries($this->votes, failChunksLargerThan: 1);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $this->assertSame(0, $tester->execute(['--dry-run' => true, '--chunk' => '3']));
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Spatial calls that failed and were stepped over', $output);
        $this->assertStringContainsString('retried one town at a time', $output);
        // Every town still got its vote through the per-town retry.
        $this->assertStringContainsString('Centroid vote: 5 towns voted', $output);
    }
}

/**
 * The command with its one spatial query replaced by injected vote rows.
 */
class FakeRelinkBarangayBoundaries extends RelinkBarangayBoundaries
{
    public function __construct(private array $votes, private int $failChunksLargerThan = PHP_INT_MAX)
    {
        parent::__construct();
    }

    protected function centroidVotes(array $parentPsgcs): array
    {
        if (count($parentPsgcs) > $this->failChunksLargerThan) {
            throw new \RuntimeException('SQLSTATE[22023]: Invalid GIS data provided to function st_centroid.');
        }

        $out = [];
        foreach ($parentPsgcs as $pcode) {
            if (isset($this->votes[$pcode])) {
                $out[$pcode] = array_map(fn ($r) => (object) $r, $this->votes[$pcode]);
            }
        }

        return $out;
    }
}
