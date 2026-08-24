<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secondary albums inside one convention's gallery.
 *
 * The convention itself (natcon_event_id) is the primary album — NATCON 2026
 * has many photographers from different companies, and each gets a named
 * folder under the year. Exactly ONE level: albums belong to an event, photos
 * belong to an album or to the event root (album_id NULL). No parent_id, so
 * nesting cannot creep in.
 *
 * An album owns no S3 object, so unlike gallery photos a real delete is safe —
 * album_id is nullOnDelete, meaning deleting a folder tips its photos back
 * into the event root instead of orphaning or deleting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_gallery_albums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One name per convention — two "Studio A" folders under 2026 would
            // be indistinguishable in every picker. ⚠️ This unique index also
            // backs the natcon_event_id FK (MySQL error 1553 if dropped
            // directly) — same caveat as natcon_gallery_page_idx.
            $table->unique(['natcon_event_id', 'name'], 'natcon_gallery_albums_event_name_uq');
        });

        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->foreignId('album_id')
                ->nullable()
                ->after('natcon_event_id')
                ->constrained('natcon_gallery_albums')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('album_id');
        });

        Schema::dropIfExists('natcon_gallery_albums');
    }
};
