<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived cache for the barangay-tier SEO pages
 * (/for-sale/house/in-{barangay}-{city}-{province}): public listing counts per
 * effective barangay × (category × property type). Effective barangay =
 * COALESCE(reverse-geocoded pin, agent-picked address_id when it doesn't
 * contradict the pin's city) — same registry semantics as the city_id filter.
 * Refreshed daily by `seo:compute-barangay-counts` so the sitemap shard,
 * frontend registry, and page gating read a precomputed table instead of a
 * live GROUP BY per request. Same role/pattern as `facility_listing_counts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barangay_listing_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('barangay_id');
            $table->string('barangay');
            $table->unsignedBigInteger('city_id');
            $table->string('city');
            $table->unsignedBigInteger('province_id');
            $table->string('province');
            $table->string('category'); // "For Sale" | "For Rent"
            $table->string('type');     // "Condominium" | "House" | ...
            $table->unsignedInteger('total');
            $table->timestamp('computed_at');

            $table->unique(['barangay_id', 'category', 'type'], 'blc_cohort_unique');
            $table->index(['category', 'type']);
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangay_listing_counts');
    }
};
