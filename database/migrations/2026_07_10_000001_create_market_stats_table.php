<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived cache for the market-stats module on typed location money pages
 * (median/average price, median price-per-sqm, listing counts) per
 * (category × property type × city|province [× bedroom count]) per MONTH.
 *
 * Monthly snapshots: `seo:compute-market-stats` upserts only the CURRENT
 * month's rows on each run — prior months are never touched, so history
 * accrues and month-over-month deltas become available from the second
 * month onward. Effective city = COALESCE(reverse-geocoded pin, agent-picked
 * address barangay's city) — the same registry semantics as the city_id
 * filter, so the counts match on-page results. Rows with any count ≥ 1 are
 * stored; display floors stay frontend-owned (same philosophy as
 * `barangay_listing_counts`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_stats', function (Blueprint $table) {
            $table->id();
            $table->string('category');            // "For Sale" | "For Rent"
            $table->string('type');                // "Condominium" | "House" | ...
            $table->string('scope', 16);           // "city" | "province"
            $table->unsignedBigInteger('city_id')->nullable();  // NULL on province rows
            $table->string('city')->nullable();
            $table->unsignedBigInteger('province_id');
            $table->string('province');
            $table->unsignedTinyInteger('bedroom_count')->nullable(); // NULL = all bedrooms
            $table->date('month');                 // first day of the stat month
            $table->unsignedInteger('listing_count');
            $table->decimal('median_price', 20, 2);
            $table->decimal('avg_price', 20, 2);
            $table->decimal('median_ppsqm', 12, 2)->nullable();
            // How many listings had usable floor_area — frontend gates the
            // ₱/sqm figure on this so a 2-listing sample never renders.
            $table->unsignedInteger('ppsqm_count')->default(0);
            $table->timestamp('computed_at');

            $table->unique(
                ['category', 'type', 'scope', 'city_id', 'province_id', 'bedroom_count', 'month'],
                'market_stats_cohort_month_unique'
            );
            $table->index(['category', 'type']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_stats');
    }
};
