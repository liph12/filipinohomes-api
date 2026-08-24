<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery photos group into ALBUMS ("Natcon 2026 Coordination Meeting",
 * "Awards Night", …) — the public gallery renders one section per album.
 *
 * A nullable string, deliberately not an albums table: an album here is only
 * a grouping label with no metadata of its own, so it exists implicitly in
 * the distinct values (the admin offers existing labels as suggestions).
 * NULL = ungrouped — those photos render in the gallery's general section.
 * If albums ever grow covers/descriptions, promote to a table then; the
 * label column migrates into it losslessly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->string('album', 120)->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_gallery_photos', function (Blueprint $table) {
            $table->dropColumn('album');
        });
    }
};
