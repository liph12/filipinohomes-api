<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Face indexing for the curated GALLERY photos — the same four columns
 * natcon_album_photos carries, so the admin's Photo search can face-search
 * the convention's public gallery, not just the raw photographer pile.
 * Existing rows start faces_indexed_at NULL, which is the whole work-list of
 * the natcon:index-gallery-faces sweep — the backfill needs no data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->json('face_ids')->nullable()->after('sort_order');
            $table->unsignedInteger('face_count')->default(0)->after('face_ids');
            $table->timestamp('faces_indexed_at')->nullable()->after('face_count');
            $table->string('index_error', 512)->nullable()->after('faces_indexed_at');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->dropColumn(['face_ids', 'face_count', 'faces_indexed_at', 'index_error']);
        });
    }
};
