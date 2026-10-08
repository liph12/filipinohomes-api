<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a VVIP's titles ("TOP 1 SUPERSTAR") are written on a frame, as fractions of its size —
     * title_x/y/w/h, like text_x/y/w/h for the name. The box is the FIRST line's place; a person with
     * more titles gets more lines stacked UP from it (smallest rank on top), never down over the name.
     */
    public function up(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            foreach (['title_x', 'title_y', 'title_w', 'title_h'] as $column) {
                if (! Schema::hasColumn('gallery_album_frames', $column)) {
                    $table->decimal($column, 6, 5)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            foreach (['title_x', 'title_y', 'title_w', 'title_h'] as $column) {
                if (Schema::hasColumn('gallery_album_frames', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
