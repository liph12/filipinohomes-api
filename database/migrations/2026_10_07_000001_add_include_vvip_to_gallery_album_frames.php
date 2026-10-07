<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An awardee frame (award_segments set) can also be offered to everyone on the VVIP
     * list — shown on their NATCON VVIP page next to the VVIP frames. A separate
     * migration because the one that created the other frame columns has already run.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('gallery_album_frames', 'include_vvip')) {
            Schema::table('gallery_album_frames', function (Blueprint $table) {
                $table->boolean('include_vvip')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gallery_album_frames', 'include_vvip')) {
            Schema::table('gallery_album_frames', fn (Blueprint $t) => $t->dropColumn('include_vvip'));
        }
    }
};
