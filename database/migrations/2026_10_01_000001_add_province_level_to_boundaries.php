<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets `boundaries` hold province polygons alongside the 1,647 city ones, so
 * the admin listings heatmap can shade the whole country without a second
 * table (and so one spatial index serves both levels).
 *
 * Two deliberate choices:
 *
 * 1. The enum is widened with raw DDL rather than a fluent `$table->enum()`
 *    change. Laravel's schema change path would rebuild the column through
 *    doctrine-style introspection, which does not understand the adjacent
 *    SPATIAL index on `geom`; the ats_status precedent
 *    (2026_04_14_200200_extend_ats_status_enum_add_rejected) does the same.
 *
 * 2. `province_id` is its OWN column rather than reusing `city_id`. A province
 *    row has no city, and the relink command sets `province_id` on city rows
 *    too (the province each city polygon geometrically falls inside), so the
 *    two columns answer different questions and the (level, province_id) index
 *    serves "all cities of province N" for the drill-down layer.
 *
 * down() deletes the province rows BEFORE dropping the column and shrinking the
 * enum: under STRICT mode, narrowing an enum while rows still carry the removed
 * value fails the ALTER outright. It never uses dropIfExists — the table itself
 * belongs to the earlier create migration and the 1,647 city rows must survive a
 * rollback drill untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE boundaries MODIFY level ENUM('city','barangay','province') NOT NULL");

        Schema::table('boundaries', function (Blueprint $table) {
            $table->unsignedBigInteger('province_id')->nullable()->after('city_id');
            $table->index(['level', 'province_id']);
            $table->foreign('province_id')->references('id')->on('provinces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Must precede the enum shrink (see docblock). Scoped to level so the
        // city rows this migration never created are left alone.
        DB::table('boundaries')->where('level', 'province')->delete();

        Schema::table('boundaries', function (Blueprint $table) {
            $table->dropForeign(['province_id']);
            $table->dropIndex(['level', 'province_id']);
            $table->dropColumn('province_id');
        });

        DB::statement("ALTER TABLE boundaries MODIFY level ENUM('city','barangay') NOT NULL");
    }
};
