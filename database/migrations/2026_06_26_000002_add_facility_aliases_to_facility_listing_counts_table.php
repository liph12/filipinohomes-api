<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalizes facility aliases into the precomputed counts table. The frontend
 * search index (searchSuggest.ts) reads ONLY /sitemap/facility-counts, so the
 * former-name tokens must travel with the count rows for old-name search
 * matching to work. Recomputed each run by seo:compute-facility-counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_listing_counts', function (Blueprint $table) {
            if (! Schema::hasColumn('facility_listing_counts', 'aliases')) {
                $table->json('aliases')->nullable()->after('facility_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('facility_listing_counts', function (Blueprint $table) {
            if (Schema::hasColumn('facility_listing_counts', 'aliases')) {
                $table->dropColumn('aliases');
            }
        });
    }
};
