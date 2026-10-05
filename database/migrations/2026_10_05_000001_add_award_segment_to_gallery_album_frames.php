<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A frame can be reserved for one OR MORE NATCON award segments
     * (Recipient::SEGMENTS) — `award_segments`, a JSON list — optionally only
     * for the awardees flagged Elite (`elite_only`). NULL = a general frame
     * offered to everyone. Segment frames are only ever served to awardees of
     * those segments.
     *
     * Re-runnable: earlier drafts of this file were already applied on some
     * machines with a single `award_segment` string and no `elite_only`.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('gallery_album_frames', 'award_segments')) {
            Schema::table('gallery_album_frames', function (Blueprint $table) {
                $table->json('award_segments')->nullable()->after('natcon_event_id');
            });
        }

        // Carry over the old single-segment value, then drop it.
        if (Schema::hasColumn('gallery_album_frames', 'award_segment')) {
            DB::table('gallery_album_frames')->whereNotNull('award_segment')->orderBy('id')->each(function ($row) {
                DB::table('gallery_album_frames')->where('id', $row->id)->update([
                    'award_segments' => json_encode([$row->award_segment]),
                ]);
            });

            Schema::table('gallery_album_frames', function (Blueprint $table) {
                $table->dropColumn('award_segment');
            });
        }

        if (! Schema::hasColumn('gallery_album_frames', 'elite_only')) {
            Schema::table('gallery_album_frames', function (Blueprint $table) {
                $table->boolean('elite_only')->default(false)->after('award_segments');
            });
        }

        // Drop the earlier draft's index if it exists (it named the old column).
        if (Schema::hasIndex('gallery_album_frames', 'gallery_album_frames_event_segment_idx')) {
            Schema::table('gallery_album_frames', function (Blueprint $table) {
                $table->dropIndex('gallery_album_frames_event_segment_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['award_segments', 'elite_only', 'award_segment'] as $column) {
            if (Schema::hasColumn('gallery_album_frames', $column)) {
                Schema::table('gallery_album_frames', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
