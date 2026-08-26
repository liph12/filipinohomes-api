<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gallery outgrows NATCON: natcon_gallery_{albums,photos} become plain
 * gallery_{albums,photos}, and natcon_event_id turns NULLABLE — a NULL event
 * is a PUBLIC album, served at /albums/{slug}; a set event is a convention
 * album, served inside /natcon/{year}/gallery exactly as before. Same rows,
 * same columns, one owner (App\Models\GalleryAlbum / GalleryPhoto).
 *
 * `slug` is what the public URL is keyed by. Only public albums (and their
 * sub-albums) carry one — convention albums stay NULL, because "Awards
 * Night" legitimately exists under 2026 AND 2027 and the slug is unique
 * site-wide. Nullable + unique: MySQL treats NULLs as distinct, so any number
 * of convention albums fit beside the public ones.
 *
 * Index and FK constraint NAMES keep their natcon_ prefix (MySQL keeps them
 * across a table rename). Renaming them means drop + recreate, and both
 * natcon_gallery_page_idx and natcon_gallery_albums_event_name_uq BACK the
 * natcon_event_id FK — dropping either directly is error 1553 (see the
 * 2026_08_21 create migration). Not worth the risk for a cosmetic name.
 *
 * The (natcon_event_id, name) unique stays: for public albums the event is
 * NULL, so it no longer guards duplicate names there — the controller's
 * friendly check covers that, and the slug unique is the hard backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('natcon_gallery_albums', 'gallery_albums');
        Schema::rename('natcon_gallery_photos', 'gallery_photos');

        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->unsignedBigInteger('natcon_event_id')->nullable()->change();
            $table->string('slug', 160)->nullable()->unique()->after('parent_id');
        });

        Schema::table('gallery_photos', function (Blueprint $table) {
            $table->unsignedBigInteger('natcon_event_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Will refuse while public (NULL-event) rows exist — that is correct:
        // they have no convention to fall back to and must be deleted first.
        Schema::table('gallery_photos', function (Blueprint $table) {
            $table->unsignedBigInteger('natcon_event_id')->nullable(false)->change();
        });

        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
            $table->unsignedBigInteger('natcon_event_id')->nullable(false)->change();
        });

        Schema::rename('gallery_photos', 'natcon_gallery_photos');
        Schema::rename('gallery_albums', 'natcon_gallery_albums');
    }
};
