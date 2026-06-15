<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo columns on visits — country / region / city resolved client-side by
 * ipinfo.io (the same source that populates user_info) and forwarded with the
 * /track/visit ping. Powers the Audience Insights geography breakdown for both
 * anonymous visitors and logged-in clients. Each column is indexed with
 * created_at since the audience query groups by geo within a date range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('country', 64)->nullable()->after('ip');
            $table->string('region', 96)->nullable()->after('country');
            $table->string('city', 96)->nullable()->after('region');
            $table->index(['country', 'created_at']);
            $table->index(['region', 'created_at']);
            $table->index(['city', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('visits_country_created_at_index');
            $table->dropIndex('visits_region_created_at_index');
            $table->dropIndex('visits_city_created_at_index');
            $table->dropColumn(['country', 'region', 'city']);
        });
    }
};
