<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived cache for "near {facility}" pages: how many public+active listings
 * fall within the radius of each facility, per (category × property type)
 * cohort. Refreshed daily by `seo:compute-facility-counts` so the sitemap +
 * gating read a precomputed table instead of running the unindexed radius scan
 * on every request. Same role as `modifier_price_thresholds`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_listing_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->string('facility_slug');
            $table->string('facility_name');
            $table->string('facility_category');
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('category'); // "For Sale" | "For Rent"
            $table->string('type');     // "Condominium" | "House" | ...
            $table->unsignedInteger('total');
            $table->timestamp('computed_at');

            $table->unique(['facility_id', 'category', 'type'], 'flc_cohort_unique');
            $table->index('facility_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_listing_counts');
    }
};
