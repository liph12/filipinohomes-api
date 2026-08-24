<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the face-search album pile: the curated gallery
 * (natcon_gallery_photos) is face-indexed at the same encode grade now, so
 * the second store is redundant. The rows go here; the S3 objects under
 * natcon/{slug}/ and the fh-gallery-{slug} Rekognition collections are
 * cleaned by `php artisan natcon:purge-album-pile` (run it BEFORE migrating
 * to also delete per-row S3 keys; after the drop it still removes the
 * collections and the whole owned S3 prefix).
 *
 * No down(): the table's contents are not reconstructable, so an empty
 * recreation would only pretend to be a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('natcon_album_photos');
    }

    public function down(): void
    {
        // Irreversible — see the class docblock.
    }
};
