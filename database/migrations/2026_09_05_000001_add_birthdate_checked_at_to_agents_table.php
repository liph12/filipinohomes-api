<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When we last ASKED Leuterio Realty for this agent's birthday, whether or not
 * it had one.
 *
 * agents.birthdate (added by 2026_06_02_000000) is filled lazily on login by
 * LrAgentBackfillService, so coverage tracks logins rather than headcount.
 * birthdays:backfill-birthdates closes that gap, chunked and paced under LR's
 * rate limit — and this column is what makes it resumable. Without it every
 * hourly run re-queries the same agents LR has no birthday for and never
 * reaches the ones further down the list: "we asked and LR didn't know" is a
 * different state from "we have never asked", and `birthdate IS NULL` cannot
 * tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->timestamp('birthdate_checked_at')->nullable()->after('birthdate');
        });

        // The backfill picks rows by (birthdate IS NULL, birthdate_checked_at)
        // over ~6k agents every hour.
        Schema::table('agents', function (Blueprint $table) {
            $table->index(['birthdate', 'birthdate_checked_at'], 'agents_birthdate_backfill_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex('agents_birthdate_backfill_idx');
            $table->dropColumn('birthdate_checked_at');
        });
    }
};
