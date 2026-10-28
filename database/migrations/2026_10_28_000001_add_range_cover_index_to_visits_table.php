<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Covering index for the Audience Insights / Business Pulse reads: they filter visits by a
     * created_at range and only read visitor_id, channel and user_id. With just (created_at) MySQL
     * judged the per-row table lookups dearer than a full scan and read all rows every query; this
     * lets it answer from the index alone.
     *
     * Guarded so it's a no-op where the index was already added by hand (prod was indexed before
     * this deploy to measure the gain).
     */
    public function up(): void
    {
        if (Schema::hasIndex('visits', 'visits_range_cover_index')) {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->index(['created_at', 'visitor_id', 'channel', 'user_id'], 'visits_range_cover_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('visits', 'visits_range_cover_index')) {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('visits_range_cover_index');
        });
    }
};
