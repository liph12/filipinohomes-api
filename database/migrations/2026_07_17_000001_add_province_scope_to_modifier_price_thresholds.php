<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the modifier_price_thresholds cohort identity for province-scope
 * rows: province cohorts share the city_id = 0 sentinel (the column stays
 * NOT NULL) with an empty city label, so the original unique key
 * (modifier × category × type × city_id) would collide across provinces.
 * province_id joins the key instead. Existing city rows are unaffected —
 * their (…, city_id) prefix was already unique, so the wider key cannot
 * introduce duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_price_thresholds', function (Blueprint $table) {
            $table->dropUnique('mpt_cohort_unique');
            // Explicit short name — the auto-generated one exceeds MySQL's
            // 64-char identifier limit.
            $table->unique(
                ['modifier', 'category_id', 'property_type_id', 'city_id', 'province_id'],
                'mpt_cohort_unique'
            );
        });
    }

    public function down(): void
    {
        // Province rows (city_id = 0) collide on the narrower key — remove
        // them before restoring it. Derived cache rows only; the next
        // seo:compute-modifier-thresholds run rebuilds whatever still applies.
        DB::table('modifier_price_thresholds')->where('city_id', 0)->delete();

        Schema::table('modifier_price_thresholds', function (Blueprint $table) {
            $table->dropUnique('mpt_cohort_unique');
            $table->unique(
                ['modifier', 'category_id', 'property_type_id', 'city_id'],
                'mpt_cohort_unique'
            );
        });
    }
};
