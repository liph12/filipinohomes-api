<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An awardee frame's conditions all have to hold at once: the award(s) ticked, Elite,
     * and VVIP. `require_vvip` is the VVIP one (the award is award_segments, Elite is
     * elite_only). It replaces the short-lived `include_vvip` ("also usable by VVIPs"),
     * which is dropped if a database has it.
     */
    public function up(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            if (! Schema::hasColumn('gallery_album_frames', 'require_vvip')) {
                $table->boolean('require_vvip')->default(false);
            }
        });

        if (Schema::hasColumn('gallery_album_frames', 'include_vvip')) {
            Schema::table('gallery_album_frames', fn (Blueprint $t) => $t->dropColumn('include_vvip'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gallery_album_frames', 'require_vvip')) {
            Schema::table('gallery_album_frames', fn (Blueprint $t) => $t->dropColumn('require_vvip'));
        }
    }
};
