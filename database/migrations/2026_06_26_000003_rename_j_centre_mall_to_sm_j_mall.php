<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebrand: J Centre Mall (Mandaue) permanently closed and reopened under SM as
 * "SM J Mall" (officially "SM City J Mall"). Update the existing facility row
 * IN PLACE — same physical building, so lat/lng/city/province are preserved —
 * change the slug to sm-j-mall, record the old name as an alias (so search still
 * matches "J Centre Mall") and the old slug as a former_slug (so the frontend
 * 301s near-j-centre-mall → near-sm-j-mall).
 *
 * Uses DB::table (no model events) and matches on the OLD slug, so it is a no-op
 * on a DB already seeded under the new slug — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('facilities')->where('slug', 'j-centre-mall')->first();
        if (! $row) {
            return; // already renamed (fresh seed) or absent — nothing to do
        }

        DB::table('facilities')->where('id', $row->id)->update([
            'name'         => 'SM J Mall',
            'slug'         => 'sm-j-mall',
            'aliases'      => json_encode(['J Centre Mall', 'SM City J Mall']),
            'former_slugs' => json_encode(['j-centre-mall']),
            'updated_at'   => now(),
            // lat / lng / city / province / category / is_active intentionally untouched
        ]);
    }

    public function down(): void
    {
        $row = DB::table('facilities')->where('slug', 'sm-j-mall')->first();
        if (! $row) {
            return;
        }

        DB::table('facilities')->where('id', $row->id)->update([
            'name'         => 'J Centre Mall',
            'slug'         => 'j-centre-mall',
            'aliases'      => null,
            'former_slugs' => null,
            'updated_at'   => now(),
        ]);
    }
};
