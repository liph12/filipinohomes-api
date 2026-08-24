<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the varchar `album` grouping label (2026_08_24_000001_add_album…).
 *
 * Two album designs landed the same day: the label column, and the
 * natcon_gallery_albums TABLE (album_id FK, nested via parent_id, admin
 * folder UI). The table design won — it carries per-album ordering, nesting,
 * and rename-without-touching-photos, none of which a label can. Nothing was
 * ever written into the label in any environment, so this is a plain drop
 * with no data to migrate; the guard makes it safe on a database where the
 * add-migration never ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('natcon_gallery_photos', 'album')) {
            return;
        }

        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->dropColumn('album');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->string('album', 120)->nullable()->after('caption');
        });
    }
};
