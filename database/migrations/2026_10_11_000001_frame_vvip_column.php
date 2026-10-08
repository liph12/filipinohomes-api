<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who can use it has two columns of checkboxes. The first (award_segments + elite_only) is for people
     * who are NOT VVIP. The second is for VVIPs and only exists when the frame's VVIP box is ticked
     * (either_vvip): its own awards / Elite (vvip_award_segments, vvip_elite_only) — NULL = it copies the
     * first column. `require_vvip` (already there) now means "VVIP only": nothing is ticked in the first
     * column, so non-VVIP people don't get the frame.
     */
    public function up(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            if (! Schema::hasColumn('gallery_album_frames', 'either_vvip')) {
                $table->boolean('either_vvip')->default(false);
            }
            if (! Schema::hasColumn('gallery_album_frames', 'vvip_award_segments')) {
                $table->json('vvip_award_segments')->nullable();
            }
            if (! Schema::hasColumn('gallery_album_frames', 'vvip_elite_only')) {
                $table->boolean('vvip_elite_only')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            foreach (['either_vvip', 'vvip_award_segments', 'vvip_elite_only'] as $column) {
                if (Schema::hasColumn('gallery_album_frames', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
