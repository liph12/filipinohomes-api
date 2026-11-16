<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Category display names: "Foreclosure" → "Foreclosed", "For Assume" →
 * "Assume Balance". Ids (3 and 4) don't change, so listings are untouched.
 *
 * Code matches categories by name in many queries, so it was updated in the
 * same change. Old names in links and filters are still accepted
 * (Category::LEGACY_NAMES).
 *
 * Query builder, not the model: App\Models\Category blocks update().
 */
return new class extends Migration
{
    private const RENAMES = [
        3 => ['Foreclosure', 'Foreclosed'],
        4 => ['For Assume', 'Assume Balance'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $id => [$old, $new]) {
            DB::table('categories')->where('id', $id)->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $id => [$old, $new]) {
            DB::table('categories')->where('id', $id)->where('name', $new)->update(['name' => $old]);
        }
    }
};
