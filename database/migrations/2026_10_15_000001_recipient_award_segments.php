<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recipient can hold SEVERAL awards (one agent is both a Global Partner and FHI Global):
     * `award_segments` is the whole set, as a JSON list of segment keys. `award_segment` stays as
     * the PRIMARY — the one printed on the card and read by everything single-valued (roster
     * filters, exports, the v2 mirror) — and is kept in step with the set by Recipient::setAwards().
     * Backfilled from the single column, so every existing awardee keeps exactly what they had.
     */
    public function up(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            if (! Schema::hasColumn('natcon_recipients', 'award_segments')) {
                $table->json('award_segments')->nullable()->after('award_segment');
            }
        });

        DB::table('natcon_recipients')
            ->whereNull('award_segments')
            ->whereNotNull('award_segment')
            ->update(['award_segments' => DB::raw('JSON_ARRAY(award_segment)')]);
    }

    public function down(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            if (Schema::hasColumn('natcon_recipients', 'award_segments')) {
                $table->dropColumn('award_segments');
            }
        });
    }
};
