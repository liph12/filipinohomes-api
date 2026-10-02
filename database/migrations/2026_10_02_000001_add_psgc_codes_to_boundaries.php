<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives `boundaries` the official PSGC codes of the barangay (ADM4) source, so
 * the barangay re-link can group 42,048 polygons by the town they belong to
 * WITHOUT trusting a name, and so a re-import can be audited against the
 * source file from SQL alone.
 *
 * Why codes and not just names: the barangay file's `ADM3_EN` repeats all over
 * the country (nine San Joses, seven Santa Cruzes), while `ADM3_PCODE` is one
 * town, once. Grouping the polygons by `parent_psgc` is the only grouping that
 * cannot merge two towns, and it is what `boundaries:relink-barangays` votes
 * and matches per group.
 *
 * The columns, all nullable because the city and province rows predate them:
 *
 *   psgc_code         ADM4_PCODE on a barangay row, ADM3_PCODE on a city row,
 *                     ADM2_PCODE on a province row when the dissolved features
 *                     all carried one. Indexed non-unique on purpose: province
 *                     rows are dissolved from several features, and nothing
 *                     here needs the database to police a code's uniqueness.
 *   parent_psgc       ADM3_PCODE on a barangay row, ADM2_PCODE on a city row —
 *                     "the town this polygon is in", as the source states it.
 *   grandparent_name  ADM2_EN on a barangay row: the PROVINCE the source files
 *                     the town under. The relink command's name path needs a
 *                     province to scope its city candidates (a bare "Talisay"
 *                     is three towns), and `parent_name` already holds the
 *                     town. The city rows keep NULL here; their province is
 *                     resolved geometrically by boundaries:relink-cities.
 *   link_how          how the relink command placed the row's TOWN:
 *                     geo+name / name-wins / geo-wins / geo-only / name-only.
 *                     Short tokens, so VARCHAR(16); NULL means "not linked".
 *
 * Fluent Schema::table only — no enum change, no geometry change, nothing that
 * would make the builder rebuild the table around the SPATIAL index. down()
 * drops the indexes and columns and leaves every row in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boundaries', function (Blueprint $table) {
            $table->string('grandparent_name')->nullable()->after('parent_name');
            $table->string('psgc_code', 16)->nullable()->after('barangay_id');
            $table->string('parent_psgc', 16)->nullable()->after('psgc_code');
            $table->string('link_how', 16)->nullable()->after('parent_psgc');

            $table->index(['level', 'parent_psgc']);
            $table->index('psgc_code');
        });
    }

    public function down(): void
    {
        Schema::table('boundaries', function (Blueprint $table) {
            $table->dropIndex(['level', 'parent_psgc']);
            $table->dropIndex(['psgc_code']);
            $table->dropColumn(['grandparent_name', 'psgc_code', 'parent_psgc', 'link_how']);
        });
    }
};
