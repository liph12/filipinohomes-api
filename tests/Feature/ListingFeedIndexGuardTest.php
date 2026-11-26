<?php

namespace Tests\Feature;

use App\Services\Listing\ListingFeed;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * The grouped result pages read each group through the `listings_public_browse_idx` index and, on MySQL, force it
 * for the unfiltered request. A database that has not run that migration would throw on every result page the moment
 * the order switch went on, so the index is only forced where it exists.
 */
class ListingFeedIndexGuardTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();
        Cache::forget('listings.browse_index_exists');
    }

    public function test_the_index_is_only_forced_where_the_database_has_it(): void
    {
        $this->assertFalse(ListingFeed::browseIndexExists()); // the test table has no such index

        Schema::table('listings', fn (Blueprint $t) => $t->index(['visibility', 'deleted_at', 'updated_at'], 'listings_public_browse_idx'));
        $this->assertFalse(ListingFeed::browseIndexExists()); // the answer is kept for an hour...

        Cache::forget('listings.browse_index_exists');
        $this->assertTrue(ListingFeed::browseIndexExists());  // ...and found once it is looked up again
    }
}
