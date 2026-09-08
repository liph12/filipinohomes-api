<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which group's award an awardee is receiving, for the ones who are not
 * Leuterio Realty agents.
 *
 * Two 2026 groups sit outside LR's qualifier list — Global Partners and FHI
 * Global — and their invitation card must not read "TOP AGENT". The card is
 * rendered by natcon-api-v2, so this column is the origin of a value that
 * travels: recipient -> the service roster -> v2's registrants -> the card.
 *
 * NULL means an LR agent, which is the default and 289 of 297 rows. A single
 * column rather than a pair of booleans because the states are mutually
 * exclusive and the card prints exactly one line — two flags would allow
 * "both", which has no meaning to render.
 *
 * No index: it selects 8 rows out of 297, and the admin's only filter for it
 * is a convenience view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->string('award_segment', 24)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->dropColumn('award_segment');
        });
    }
};
