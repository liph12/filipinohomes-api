<?php

namespace Tests\Concerns;

use App\Services\Listing\ListingFeedSwitch;
use App\Services\Listing\ListingPriceRules;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables behind the public listings endpoint and the price / rotation services: listings with a price and
 * a category, their property, attributes and type, the agent who owns them, the settings table the switches live
 * in, and the small lookups the resources read (furnishing, address, page builder, assume money).
 *
 * Sits on top of CreatesAuthTables (roles, users, agents, audits): call createAuthTables() first. Same approach as
 * the other feature tests (the full migration suite is MySQL-only): trimmed to the columns the code reads, no
 * foreign keys. Categories are seeded with their real ids: 1 For Sale, 2 For Rent, 3 Foreclosed, 4 Assume Balance.
 */
trait CreatesListingFeedTables
{
    private int $feedAgentCount = 0;

    protected function createListingFeedTables(): void
    {
        Schema::table('agents', function (Blueprint $t) {
            $t->decimal('unanswered_response_pct', 5, 2)->nullable();
            $t->unsignedInteger('response_sample_size')->nullable();
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'For Sale'], ['id' => 2, 'name' => 'For Rent'],
            ['id' => 3, 'name' => 'Foreclosed'], ['id' => 4, 'name' => 'Assume Balance'],
        ]);

        Schema::create('property_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        DB::table('property_types')->insert([
            ['id' => 1, 'name' => 'House'], ['id' => 2, 'name' => 'Condominium'],
            ['id' => 3, 'name' => 'Land'], ['id' => 4, 'name' => 'Commercial'],
        ]);
        Schema::create('property_subtypes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_type_id');
            $t->string('name');
            $t->timestamps();
        });
        DB::table('property_subtypes')->insert([
            ['id' => 1, 'property_type_id' => 1, 'name' => 'House and Lot'],
            ['id' => 2, 'property_type_id' => 2, 'name' => 'Studio'],
            ['id' => 3, 'property_type_id' => 3, 'name' => 'Residential Lot'],
            ['id' => 4, 'property_type_id' => 4, 'name' => 'Office'],
        ]);
        Schema::create('property_attributes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_subtype_id')->nullable();
            $t->decimal('lot_area', 12, 2)->nullable();
            $t->decimal('floor_area', 12, 2)->nullable();
            $t->unsignedInteger('bedroom_count')->nullable();
            $t->unsignedInteger('bathroom_count')->nullable();
            $t->unsignedInteger('garage_count')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('furnishings', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        DB::table('furnishings')->insert(['id' => 1, 'name' => 'Unfurnished']);

        foreach (['provinces' => null, 'cities' => 'province_id', 'barangays' => 'city_id'] as $table => $parent) {
            Schema::create($table, function (Blueprint $t) use ($parent) {
                $t->id();
                $t->string('name')->nullable();
                if ($parent) {
                    $t->unsignedBigInteger($parent)->nullable();
                }
                $t->timestamps();
            });
        }

        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('property_attribute_id')->nullable();
            $t->unsignedBigInteger('furnishing_id')->nullable();
            $t->unsignedBigInteger('address_id')->nullable();
            $t->string('status')->default('active');
            $t->text('photos')->nullable();
            $t->text('description')->nullable();
            $t->text('geo_coordinates')->nullable();
            $t->text('address')->nullable();
            $t->boolean('is_project')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->decimal('price', 15, 2)->nullable();
            $t->string('visibility')->default('public');
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('property_id');
            $t->unsignedBigInteger('agent_id');
            $t->boolean('is_featured')->default(false);
            $t->unsignedBigInteger('clicks')->default(0);
            $t->unsignedBigInteger('impressions')->default(0);
            $t->text('featured_photo')->nullable();
            $t->string('verification_status')->nullable();
            $t->text('agent_edited_fields')->nullable();
            $t->timestamp('audited_at')->nullable();
            $t->timestamp('re_submitted_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('assume_details', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_id');
            $t->timestamps();
        });
        Schema::create('page_builder', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agent_id');
            $t->string('slug')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('team_agents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->unsignedBigInteger('agent_id');
            $t->boolean('is_leader')->default(false);
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('scheduler_states', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->unsignedBigInteger('last_id')->default(0);
            $t->timestamps();
        });
        Schema::create('listing_sold_claims', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_id');
            $t->string('tier');
            $t->timestamp('said_at');
            $t->timestamps();
        });

        // The switches memoise what they read and the category ids are memoised: start every test clean.
        ListingFeedSwitch::flush();
        ListingPriceRules::flush();
    }

    /** An agent (with a user) in the given state; returns [agent id, user id]. */
    protected function feedAgent(string $status = 'active', ?string $lastOnline = null): array
    {
        $n = ++$this->feedAgentCount;
        $userId = DB::table('users')->insertGetId([
            'name' => "Agent {$n}", 'email' => "feed-agent-{$n}@example.test", 'password' => 'x',
            'role_id' => DB::table('roles')->where('name', 'agent')->value('id'),
            'last_online_at' => $lastOnline,
        ]);
        $agentId = DB::table('agents')->insertGetId([
            'user_id' => $userId, 'first_name' => 'Agent', 'last_name' => (string) $n, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agentId, $userId];
    }

    /**
     * One listing with its property. `$o` overrides: price, category (id), type (name: House, Condominium, Land,
     * Commercial), lot, floor, status (the property's), visibility, name, agent (id), updated (a timestamp),
     * photos (count), description (chars), pin (bool), verification, created.
     *
     * @param  array<string, mixed>  $o
     */
    protected function feedListing(array $o = []): int
    {
        static $seq = 0;
        $seq++;

        $agent = $o['agent'] ?? $this->feedAgent()[0];
        $subtypeId = ['House' => 1, 'Condominium' => 2, 'Land' => 3, 'Commercial' => 4][$o['type'] ?? 'House'];
        $attributeId = DB::table('property_attributes')->insertGetId([
            'property_subtype_id' => $subtypeId, 'lot_area' => $o['lot'] ?? null, 'floor_area' => $o['floor'] ?? null,
        ]);
        $photos = array_map(fn ($i) => "https://example.test/p{$i}.jpg", range(1, $o['photos'] ?? 0));
        $propertyId = DB::table('properties')->insertGetId([
            'property_attribute_id' => $attributeId, 'furnishing_id' => 1,
            'status' => $o['status'] ?? 'active',
            'photos' => json_encode($photos),
            'description' => str_repeat('x', $o['description'] ?? 0),
            'geo_coordinates' => ($o['pin'] ?? false) ? json_encode(['lat' => 10.31, 'lng' => 123.89]) : null,
        ]);

        return DB::table('listings')->insertGetId([
            'code' => "T-{$seq}", 'name' => $o['name'] ?? "Listing {$seq}", 'slug' => "listing-{$seq}",
            'price' => array_key_exists('price', $o) ? $o['price'] : 1_500_000,
            'visibility' => $o['visibility'] ?? 'public',
            'category_id' => $o['category'] ?? 1,
            'property_id' => $propertyId, 'agent_id' => $agent,
            'verification_status' => $o['verification'] ?? null,
            'created_at' => $o['created'] ?? now()->subYear(),
            'updated_at' => $o['updated'] ?? now()->subDays($seq),
        ]);
    }

    /** A listing that passes every completeness test: 5 photos, 120 chars, a pin in the Philippines, 40 sqm. */
    protected function completeListing(array $o = []): int
    {
        return $this->feedListing($o + ['photos' => 5, 'description' => 120, 'pin' => true, 'floor' => 40]);
    }
}
