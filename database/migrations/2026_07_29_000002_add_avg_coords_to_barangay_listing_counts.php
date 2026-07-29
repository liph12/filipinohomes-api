<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Average listing coordinates per barangay cohort — computed by
 * seo:compute-barangay-counts (AVG over each cohort's listing pins) so the
 * admin SEO Manage Barangays map can plot a pin where the inventory actually
 * sits. Nullable: a cohort whose listings all lack geo pins has no average.
 * Served via /sitemap/barangay-counts only when ?v>=2 (marketStats pattern)
 * so existing consumers' cached payload shape is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barangay_listing_counts', function (Blueprint $table) {
            $table->decimal('avg_lat', 10, 7)->nullable()->after('total');
            $table->decimal('avg_lng', 10, 7)->nullable()->after('avg_lat');
        });
    }

    public function down(): void
    {
        Schema::table('barangay_listing_counts', function (Blueprint $table) {
            $table->dropColumn(['avg_lat', 'avg_lng']);
        });
    }
};
