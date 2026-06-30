<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // FH office-region key (e.g. "cebu", "cagayan") derived from the LR
            // `state`. Indexed — this is the column the secretary scoping filters on
            // (whereHas('agent', region = ?)).
            $table->string('region')->nullable()->after('gender')->index();
            // Raw LR `state` (e.g. "Bukidnon") so region can be re-derived later
            // (OfficeRegionMap changes) without re-hitting the LR API.
            $table->string('lr_state')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['region']);
            $table->dropColumn(['region', 'lr_state']);
        });
    }
};
