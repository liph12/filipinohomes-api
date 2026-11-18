<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use App\Support\FhOneKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAuthTables;
use Tests\TestCase;

/**
 * GET /filipinohomes/listings — the listings API for FH One: every listing that isn't deleted (any
 * visibility / status / audit result), newest first, paginated. It needs FH One's key
 * (FhOneApiTest covers who is let in); here every call carries a good one.
 */
class FhoneListingsApiTest extends TestCase
{
    use CreatesAuthTables;

    private const KEY = 'fhone_listings_test_key_0123456789abcdefghijklmnop';

    protected function setUp(): void
    {
        parent::setUp();

        config(['fhone.keys' => FhOneKeys::entry('test-key', self::KEY, '2099-12-31')]);
        $this->withToken(self::KEY);

        $this->createAuthTables();
        foreach (['property_types', 'property_subtypes', 'furnishings', 'categories'] as $t) {
            Schema::create($t, function (Blueprint $table) use ($t) {
                $table->id();
                $table->string('name')->nullable();
                if ($t === 'property_subtypes') {
                    $table->unsignedBigInteger('property_type_id')->nullable();
                }
                $table->timestamps();
            });
        }
        Schema::create('property_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_subtype_id')->nullable();
            $table->timestamps();
        });
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->date('status_change_date')->nullable();
            $table->unsignedBigInteger('property_attribute_id')->nullable();
            $table->unsignedBigInteger('furnishing_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->string('visibility')->default('public');
            $table->string('verification_status')->nullable();
            $table->text('featured_photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /** A listing (and its property); returns the listing id. */
    private function listing(string $code, array $listing = [], array $property = []): int
    {
        $propertyId = DB::table('properties')->insertGetId($property + ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('listings')->insertGetId($listing + [
            'property_id' => $propertyId,
            'code' => $code,
            'name' => "Listing $code",
            'slug' => strtolower($code),
            'visibility' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function codes($response): array
    {
        return collect($response->json('data'))->pluck('code')->sort()->values()->all();
    }

    public function test_it_returns_every_listing_that_is_not_deleted(): void
    {
        $this->listing('PUBLIC');
        $this->listing('PRIVATE', ['visibility' => 'private']);
        $this->listing('SOLD', [], ['status' => 'sold']);
        $this->listing('TRASHED', ['deleted_at' => now()]);                // listing soft-deleted
        $this->listing('PROP-TRASHED', [], ['deleted_at' => now()]);       // property soft-deleted
        $this->listing('PROP-DELETED', [], ['status' => 'deleted']);       // property marked deleted

        $res = $this->getJson('/api/filipinohomes/listings')->assertOk();

        $this->assertSame(['PRIVATE', 'PUBLIC', 'SOLD'], $this->codes($res));
        $this->assertSame(3, $res->json('meta.total'));
        $private = collect($res->json('data'))->firstWhere('code', 'PRIVATE');
        $this->assertSame('private', $private['visibility']);
        $this->assertArrayHasKey('status', $private);
        $this->assertArrayHasKey('updated_at', $private);
    }

    public function test_filters_and_paging(): void
    {
        $this->listing('A', [], ['status' => 'active']);
        $this->listing('B', ['visibility' => 'private'], ['status' => 'sold']);
        $this->listing('C', ['updated_at' => now()->subMonth()], ['status' => 'rented']);

        $this->assertSame(['B', 'C'], $this->codes($this->getJson('/api/filipinohomes/listings?status=sold,rented')->assertOk()));
        $this->assertSame(['B'], $this->codes($this->getJson('/api/filipinohomes/listings?visibility=private')->assertOk()));
        $this->assertSame(['A', 'B'], $this->codes($this->getJson('/api/filipinohomes/listings?updated_since='.now()->subDay()->toDateString())->assertOk()));

        $page = $this->getJson('/api/filipinohomes/listings?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(2, $page->json('meta.last_page'));

        $this->getJson('/api/filipinohomes/listings?per_page=500')->assertStatus(422);
        $this->getJson('/api/filipinohomes/listings?status=Robert%27);DROP')->assertStatus(422);
    }
}
