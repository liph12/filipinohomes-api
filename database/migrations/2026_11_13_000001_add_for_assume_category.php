<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fourth listing category: "For Assume" (pasalo).
 *
 * A pasalo unit is one whose original buyer can no longer keep paying: a new
 * buyer pays them a CASH-OUT and takes over the balance still owed to the
 * developer, bank or Pag-IBIG. Those listings already exist on the site — ~208
 * of them — posted as ordinary For Sale listings where `price` holds only the
 * cash-out, so the larger half of the deal was invisible and the mortgage
 * calculator read several times under the real commitment.
 *
 * Giving them a category of their own is what lets `assume_details` hang real
 * money fields off the listing instead of guessing from the title.
 *
 * ⚠️ Written with the query builder, not the model: App\Models\Category
 * deliberately blocks save()-on-existing / update() / delete(), and the table
 * has no timestamps, so Category::create() would fail on an unknown column.
 * Id is pinned to 4 to match the fixed ids in CategorySeeder.
 */
return new class extends Migration
{
    private const ID = 4;
    private const NAME = 'For Assume';

    public function up(): void
    {
        DB::table('categories')->updateOrInsert(
            ['id' => self::ID],
            ['name' => self::NAME]
        );
    }

    public function down(): void
    {
        // Never orphan listings: listings.category_id is restrictOnDelete, so
        // dropping a category still in use would fail anyway — check first and
        // leave the row alone if anything references it.
        $inUse = DB::table('listings')->where('category_id', self::ID)->exists();

        if (! $inUse) {
            DB::table('categories')->where('id', self::ID)->delete();
        }
    }
};
