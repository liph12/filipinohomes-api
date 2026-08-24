<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Albums can now nest: an album may live inside another album of the SAME
 * convention, to any depth (photographer → day → venue …). NULL parent_id =
 * a top-level album directly under the convention (the primary album).
 *
 * nullOnDelete is a backstop only — the controller refuses to delete an album
 * that still holds photos or sub-albums, so the FK action should never fire;
 * if it somehow does, children are promoted to top level rather than dropped.
 *
 * The event-wide unique name (event_id, name) is KEPT rather than widened to
 * (event_id, parent_id, name): MySQL treats NULLs as distinct in unique
 * indexes, which would silently allow duplicate top-level names — and the
 * flat "move photo to album" picker needs unambiguous names anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_gallery_albums', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('natcon_event_id')
                ->constrained('natcon_gallery_albums')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('natcon_gallery_albums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
