<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convention-level frames.
 *
 * Frames used to hang off one album only. The NATCON admin manages this
 * year's frames in ONE place — the convention root ("NATCON 2026"), not per
 * photographer album — so a frame can now belong to an EVENT instead:
 * exactly one of album_id / natcon_event_id is set (enforced in the
 * controller). Event frames are offered on every photo of that convention,
 * whatever album it sits in; public albums keep the per-album shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            $table->dropForeign(['album_id']);
        });
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            $table->unsignedBigInteger('album_id')->nullable()->change();
            $table->foreign('album_id')->references('id')->on('gallery_albums')->cascadeOnDelete();
            $table->foreignId('natcon_event_id')->nullable()->after('album_id')
                ->constrained('natcon_events')->cascadeOnDelete();
            $table->index(['natcon_event_id', 'status', 'sort_order'], 'gallery_album_frames_event_status_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            $table->dropIndex('gallery_album_frames_event_status_sort_idx');
            $table->dropConstrainedForeignId('natcon_event_id');
        });
    }
};
