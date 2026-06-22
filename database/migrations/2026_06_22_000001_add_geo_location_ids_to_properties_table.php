<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Reverse-geocoded location from the property's map pin
            // (geo_coordinates). The barangay dropdown (address_id) is
            // agent-picked and frequently wrong; the pin is the reliable
            // signal. Province/island derive from geo_city's province via the
            // trusted cities→provinces hierarchy, so we only cache city +
            // barangay. Nullable: null = not yet geocoded (analytics falls back
            // to the address_id chain).
            $table->unsignedBigInteger('geo_city_id')->nullable()->after('address_id');
            $table->unsignedBigInteger('geo_barangay_id')->nullable()->after('geo_city_id');
            $table->timestamp('geo_geocoded_at')->nullable()->after('geo_barangay_id');
            $table->index('geo_city_id');
            $table->index('geo_barangay_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['geo_city_id']);
            $table->dropIndex(['geo_barangay_id']);
            $table->dropColumn(['geo_city_id', 'geo_barangay_id', 'geo_geocoded_at']);
        });
    }
};
