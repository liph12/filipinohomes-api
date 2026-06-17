<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived cache table for programmatic-SEO "modifier" pages whose definition is
 * a price threshold (e.g. "affordable-condo-for-sale-{city}"). One row per
 * (modifier × category × property type × city) cohort, refreshed daily by the
 * `seo:compute-modifier-thresholds` command. Affordability is cohort-relative:
 * ₱3M is affordable for a Makati condo but not a Davao lot, so each cohort gets
 * its own percentile-derived ceiling. Names are denormalized alongside the ids
 * so the frontend can match by the same city/province slug it already builds
 * for location pages without an extra join.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a prior run may have created the table before failing on
        // an over-long auto-generated index name, leaving it un-recorded. This
        // table is a derived cache (no precious data), so dropping is safe.
        Schema::dropIfExists('modifier_price_thresholds');

        Schema::create('modifier_price_thresholds', function (Blueprint $table) {
            $table->id();
            // Which percentile-based modifier this row defines. Kept as a column
            // (not assumed "affordable") so the same table can later hold other
            // price-band modifiers without a schema change.
            $table->string('modifier')->default('affordable');

            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('property_type_id');
            $table->unsignedBigInteger('city_id');
            $table->unsignedBigInteger('province_id');

            // Denormalized labels for slug matching + on-page copy.
            $table->string('category');
            $table->string('type');
            $table->string('city');
            $table->string('province');

            // The computed ceiling: a listing in this cohort is "affordable" when
            // price <= percentile_price. sample_size is the trimmed cohort size
            // the percentile was computed from (gated at a minimum, see service).
            $table->decimal('percentile_price', 20, 2);
            $table->unsignedInteger('sample_size');
            $table->timestamp('computed_at');

            $table->unique(
                ['modifier', 'category_id', 'property_type_id', 'city_id'],
                'mpt_cohort_unique'
            );
            // Explicit short name — the auto-generated one exceeds MySQL's
            // 64-char identifier limit.
            $table->index(
                ['modifier', 'category_id', 'property_type_id'],
                'mpt_modifier_cat_type_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_price_thresholds');
    }
};
