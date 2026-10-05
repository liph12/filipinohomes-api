<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an awardee's name is written on a frame (its "name plate"), as
     * fractions of the frame's own size — set by hand in the admin Frames
     * dialog because every frame lays its plate out differently. All four are
     * NULL until someone sets it.
     */
    public function up(): void
    {
        foreach (['text_x', 'text_y', 'text_w', 'text_h'] as $column) {
            if (! Schema::hasColumn('gallery_album_frames', $column)) {
                Schema::table('gallery_album_frames', function (Blueprint $table) use ($column) {
                    $table->decimal($column, 6, 5)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['text_x', 'text_y', 'text_w', 'text_h'] as $column) {
            if (Schema::hasColumn('gallery_album_frames', $column)) {
                Schema::table('gallery_album_frames', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
