<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NATCON frame audiences + the VVIP list, in one migration.
     *
     * Frames (gallery_album_frames):
     *  - award_segments  JSON list of Recipient::SEGMENTS the frame is reserved for (NULL = everyone)
     *  - elite_only      within those segments, only awardees flagged Elite
     *  - vvip / vvip_types / vvip_category / vvip_rank
     *                    a VVIP frame: for people on the VVIP list carrying ANY of vvip_types
     *                    (logo types), optionally limited to one category / rank
     *  - text_x/y/w/h    where the awardee's name is written on the frame (fractions of its size)
     *
     * VVIP list (natcon_vvip_entries): one row per person per award category,
     * imported per convention — free-text category, rank, name, email, and the
     * logo types they carry (elite_circle, global_partners, fhi_dubai, rm_pro).
     *
     * Guarded with hasColumn / hasTable so it is safe on a database that already
     * has any of these (an earlier draft was split across several files).
     */
    public function up(): void
    {
        Schema::table('gallery_album_frames', function (Blueprint $table) {
            if (! Schema::hasColumn('gallery_album_frames', 'award_segments')) {
                $table->json('award_segments')->nullable();
            }
            if (! Schema::hasColumn('gallery_album_frames', 'elite_only')) {
                $table->boolean('elite_only')->default(false);
            }
            if (! Schema::hasColumn('gallery_album_frames', 'vvip')) {
                $table->boolean('vvip')->default(false);
            }
            if (! Schema::hasColumn('gallery_album_frames', 'vvip_types')) {
                $table->json('vvip_types')->nullable();
            }
            if (! Schema::hasColumn('gallery_album_frames', 'vvip_category')) {
                $table->string('vvip_category', 120)->nullable();
            }
            if (! Schema::hasColumn('gallery_album_frames', 'vvip_rank')) {
                $table->unsignedSmallInteger('vvip_rank')->nullable();
            }
            foreach (['text_x', 'text_y', 'text_w', 'text_h'] as $column) {
                if (! Schema::hasColumn('gallery_album_frames', $column)) {
                    $table->decimal($column, 6, 5)->nullable();
                }
            }
        });

        if (! Schema::hasTable('natcon_vvip_entries')) {
            Schema::create('natcon_vvip_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();
                // Free text — whatever the sheet calls the award ("Top Sales Agents").
                $table->string('category', 120);
                $table->unsignedSmallInteger('rank')->nullable();
                $table->string('name', 191);
                // Lowercased. NULL = the row had no email (kept for the viewer, but it can't match a login).
                $table->string('email', 191)->nullable();
                $table->json('types')->nullable();
                $table->timestamps();

                $table->index(['natcon_event_id', 'email']);
                $table->index(['natcon_event_id', 'category', 'rank']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_vvip_entries');

        Schema::table('gallery_album_frames', function (Blueprint $table) {
            foreach (['award_segments', 'elite_only', 'vvip', 'vvip_types', 'vvip_category', 'vvip_rank', 'text_x', 'text_y', 'text_w', 'text_h'] as $column) {
                if (Schema::hasColumn('gallery_album_frames', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
