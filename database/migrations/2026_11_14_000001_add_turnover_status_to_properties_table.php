<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unit's construction stage: 'pre_selling' (construction not started or
 * just started), 'under_construction' (nearly ready) or 'rfo' (ready for
 * occupancy). NULL = not specified.
 *
 * Separate from `status` (active / sold / rented / leased): that is whether the
 * property is still available, this is the construction stage — a sold unit can
 * be either. Same name and meaning as assume_details.turnover_status.
 *
 * A short string, not an enum, so a value can be added without raw DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('turnover_status', 20)->nullable()->after('status'); // pre_selling | under_construction | rfo
            $table->index('turnover_status');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['turnover_status']);
            $table->dropColumn('turnover_status');
        });
    }
};
