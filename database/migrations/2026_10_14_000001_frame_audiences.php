<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Who can use it" as a LIST of audiences: `audiences` is a JSON array of
     * {awards: [...], elite: bool, vvip: bool}, one per row the admin adds, and a person must match a
     * row EXACTLY (their award in its awards — none ticked = no award; Elite and VVIP the same as the
     * row's ticks). It replaces the three-column model (award_segments / elite_only / either_elite /
     * elite_award_segments / either_vvip / require_vvip / vvip_award_segments / vvip_elite_only), which
     * stays in place for frames not re-saved yet: NULL here = derive the rows from those columns.
     */
    public function up(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            if (! Schema::hasColumn('gallery_album_frames', 'audiences')) {
                $table->json('audiences')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            if (Schema::hasColumn('gallery_album_frames', 'audiences')) {
                $table->dropColumn('audiences');
            }
        });
    }
};
