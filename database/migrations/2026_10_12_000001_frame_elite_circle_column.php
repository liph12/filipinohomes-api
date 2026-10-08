<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A third column in Who can use it, for people in the Elite Circle (the Elite toggle): gated by its own
     * "Elite Circle" box (either_elite); its awards (elite_award_segments) — NULL = it copies the first
     * column. Elite people who are not VVIP are judged by this column when the frame has it.
     */
    public function up(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            if (! Schema::hasColumn('gallery_album_frames', 'either_elite')) {
                $table->boolean('either_elite')->default(false);
            }
            if (! Schema::hasColumn('gallery_album_frames', 'elite_award_segments')) {
                $table->json('elite_award_segments')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            foreach (['either_elite', 'elite_award_segments'] as $column) {
                if (Schema::hasColumn('gallery_album_frames', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
