<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of the convention's gallery albums the AWARDEE frames' "Find my photos" searches:
     * a JSON list of album ids (sub-albums included), NULL / empty = the whole convention.
     * Per-year config, so it lives on the event row (set from the Frames dialog, Awardee tab).
     */
    public function up(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            if (! Schema::hasColumn('natcon_events', 'awardee_face_album_ids')) {
                $table->json('awardee_face_album_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            if (Schema::hasColumn('natcon_events', 'awardee_face_album_ids')) {
                $table->dropColumn('awardee_face_album_ids');
            }
        });
    }
};
