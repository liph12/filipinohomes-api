<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI Report writes one analysis per page (`scope`: overview, agents, …)
 * and date range. `stable_hash` hashes only the facts measured over the
 * range — "as of now" figures move by the minute, so a nightly analysis would
 * otherwise look out of date by breakfast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executive_report_analyses', function (Blueprint $table) {
            $table->string('scope', 16)->default('overview')->after('id');
            $table->char('stable_hash', 64)->nullable()->after('facts_hash');
            $table->index(['scope', 'date_start', 'date_end', 'id'], 'executive_report_analyses_scope_range_index');
        });

        if (Schema::hasIndex('executive_report_analyses', ['date_start', 'date_end', 'id'])) {
            Schema::table('executive_report_analyses', function (Blueprint $table) {
                $table->dropIndex(['date_start', 'date_end', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('executive_report_analyses', function (Blueprint $table) {
            $table->index(['date_start', 'date_end', 'id']);
            $table->dropIndex('executive_report_analyses_scope_range_index');
            $table->dropColumn(['scope', 'stable_hash']);
        });
    }
};
