<?php

namespace Tests\Feature;

use App\Services\Listing\ListingPriceInsightsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The price-cleaning rules — which listings have a believable asking price.
 *
 * Price Insights and the Listings Heatmap's area details both quote "typical"
 * prices, and they must agree on which listings count: public, not flagged,
 * priced inside the floor and ceiling of their own category (For Rent ₱1k to
 * ₱10M a month; For Sale and Foreclosed ₱100k to ₱5B). These tests pin those
 * rules at every edge through Price Insights' own methods, so the rules can
 * live in one shared place without the tile's numbers moving.
 */
class ListingPriceRulesTest extends TestCase
{
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
            $table->unsignedBigInteger('prov_id')->nullable();
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
            $table->unsignedInteger('garage_count')->nullable();
            $table->softDeletes();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('address_id')->nullable();
            $table->unsignedBigInteger('property_attribute_id');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            // Nullable here (NOT NULL in MySQL) so the whereNotNull guard has something to refuse.
            $table->decimal('price', 20, 2)->nullable();
            $table->string('visibility')->default('public');
            $table->string('verification_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('provinces')->insert(['id' => 25, 'name' => 'Cebu']);
        DB::table('cities')->insert(['id' => 1, 'name' => 'Cebu City', 'province_id' => 25]);
        DB::table('barangays')->insert(['id' => 11, 'name' => 'Lahug', 'city_id' => 1]);
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'For Sale'],
            ['id' => 2, 'name' => 'For Rent'],
            ['id' => 3, 'name' => 'Foreclosed'],
        ]);
        DB::table('property_types')->insert(['id' => 1, 'name' => 'House']);
        DB::table('property_subtypes')->insert(['id' => 1, 'name' => 'House and Lot', 'property_type_id' => 1]);

        $this->seedPrices();
    }

    /** [category, price, visibility, verification_status] — each row one edge of the rules. */
    private const LISTINGS = [
        // For Sale: ₱100k ≤ price ≤ ₱5B, public, not flagged.
        [1, 99_999.99, 'public', null],             // out: under the floor
        [1, 100_000, 'public', null],               // in: the floor itself
        [1, 5_000_000_000, 'public', null],         // in: the ceiling itself
        [1, 5_000_000_001, 'public', null],         // out: over the ceiling
        [1, 3_000_000, 'private', null],            // out: not public
        [1, 3_000_000, 'public', 'flagged'],        // out: flagged
        [1, 4_000_000, 'public', 'verified'],       // in
        [1, 2_000_000, 'public', 'pending_review'], // in: only "flagged" is refused
        [1, null, 'public', null],                  // out: no price at all
        // For Rent: ₱1k ≤ price ≤ ₱10M.
        [2, 999, 'public', null],                   // out
        [2, 1_000, 'public', null],                 // in
        [2, 10_000_000, 'public', null],            // in
        [2, 10_000_001, 'public', null],            // out
        [2, 25_000, 'public', 'fully_verified'],    // in
        [2, 50_000, 'public', 'flagged'],           // out
        [2, 20_000, 'private', null],               // out
        // Foreclosed: the For Sale floor and ceiling.
        [3, 99_000, 'public', null],                // out
        [3, 1_500_000, 'public', null],             // in
        [3, 6_000_000_000, 'public', null],         // out
    ];

    private function seedPrices(): void
    {
        foreach (self::LISTINGS as $i => [$categoryId, $price, $visibility, $verification]) {
            $id = 100 + $i;
            DB::table('property_attributes')->insert(['id' => $id, 'property_subtype_id' => 1, 'floor_area' => 100, 'lot_area' => 200]);
            DB::table('properties')->insert(['id' => $id, 'address_id' => 11, 'property_attribute_id' => $id, 'status' => 'active']);
            DB::table('listings')->insert([
                'id' => $id, 'category_id' => $categoryId, 'property_id' => $id, 'agent_id' => 7,
                'price' => $price, 'visibility' => $visibility, 'verification_status' => $verification,
            ]);
        }
    }

    public function test_the_section_tile_counts_and_medians_only_believable_prices(): void
    {
        $totals = app(ListingPriceInsightsService::class)->totals([]);

        // For Sale: ₱100k, ₱2M, ₱4M, ₱5B — an even count, so the middle two average.
        $this->assertSame(4, $totals['sale']['listings']);
        $this->assertEquals(3_000_000, $totals['sale']['median_price']);
        $this->assertEquals(100_000, $totals['sale']['min_price']);
        $this->assertEquals(5_000_000_000, $totals['sale']['max_price']);

        // For Rent: ₱1k, ₱25k, ₱10M — odd, so the middle one.
        $this->assertSame(3, $totals['rent']['listings']);
        $this->assertEquals(25_000, $totals['rent']['median_price']);
        $this->assertEquals(1_000, $totals['rent']['min_price']);
        $this->assertEquals(10_000_000, $totals['rent']['max_price']);
    }

    public function test_all_categories_together_hold_each_listing_to_its_own_categorys_floor(): void
    {
        $insights = app(ListingPriceInsightsService::class);

        // 4 For Sale + 3 For Rent + the one ₱1.5M foreclosure: a ₱1k rent passes
        // on the rent floor while a ₱99k foreclosure fails on the sale floor.
        $this->assertSame(8, $insights->listings(['city_id' => 1])['meta']['total']);

        $byPlace = $insights->byPlace(['level' => 'city']);
        $this->assertSame(8, $byPlace['meta']['listings']);
        $this->assertSame(8, $byPlace['data'][0]['listings']);
        // ₱1k, ₱25k, ₱100k, ₱1.5M, ₱2M, ₱4M, ₱10M, ₱5B → (₱1.5M + ₱2M) / 2.
        $this->assertEquals(1_750_000, $byPlace['data'][0]['median_price']);

        $this->assertSame(1, $insights->listings(['city_id' => 1, 'category' => 'Foreclosed'])['meta']['total']);
        $this->assertSame(4, $insights->listings(['city_id' => 1, 'category' => 'For Sale'])['meta']['total']);
        $this->assertSame(3, $insights->listings(['city_id' => 1, 'category' => 'For Rent'])['meta']['total']);
    }
}
