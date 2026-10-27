<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10th nearby-facility category: transport (MRT/LRT/train stations, bus and jeepney/UV terminals — Google
 * `transit_station`). Collected for NEW listings only by the create-listing wizard; existing rows stay null
 * (no backfill), and every reader treats a missing category as empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nearby_facilities', function (Blueprint $table) {
            $table->json('transport_station')->nullable()->after('attraction');
        });
    }

    public function down(): void
    {
        Schema::table('nearby_facilities', function (Blueprint $table) {
            $table->dropColumn('transport_station');
        });
    }
};
