<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decorative FRAME overlays for PUBLIC gallery albums ("Vietnam 2026" gets
 * Vietnam-themed frames): admin-uploaded PNGs with a transparent photo
 * window, offered on /albums so a visitor can composite their photo into
 * one and download it.
 *
 * window_x/y/w/h locate that transparent window as FRACTIONS of the frame's
 * own dimensions — detected client-side from the PNG's alpha channel at
 * upload time. Fractions, not pixels, so the client can composite at any
 * resolution.
 *
 * cascadeOnDelete vs the "row is the only S3 pointer" rule: destroyAlbum
 * flips an album's live frames to status=deleted per-row BEFORE the album
 * delete, so the audit trail keeps every s3_key even after the cascade
 * removes the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_album_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained('gallery_albums')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('image_url', 2048);
            $table->string('s3_key', 1024);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            // The photo window, as fractions (0..1) of the frame's own size.
            $table->decimal('window_x', 6, 5);
            $table->decimal('window_y', 6, 5);
            $table->decimal('window_w', 6, 5);
            $table->decimal('window_h', 6, 5);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active | deleted
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['album_id', 'status', 'sort_order'], 'gallery_album_frames_album_status_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_album_frames');
    }
};
