<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp `invite:{id}` onto the gallery audit rows written before
 * GalleryPhoto::generateTags() existed.
 *
 * The photographer History screen reads the invite out of audits.tags rather
 * than joining back to gallery_photos, because a permanently deleted photo has
 * no row left to join to. Rows written before that tag existed would be
 * invisible there — including the uploads an admin is most likely to go
 * looking for.
 *
 * ⚠️ Run this BEFORE anyone empties the trash. Once a photo row is gone its
 *    audits can no longer be matched to an invite by any means, so the
 *    backfill has to happen while the join still resolves.
 *
 * Data only — no schema change. Untagged rows only, so it never overwrites a
 * tag the model wrote, and it is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audits') || ! Schema::hasTable('gallery_photos')) {
            return;
        }

        DB::table('audits')
            ->join('gallery_photos', 'gallery_photos.id', '=', 'audits.auditable_id')
            ->where('audits.auditable_type', 'App\\Models\\GalleryPhoto')
            ->whereNull('audits.tags')
            ->whereNotNull('gallery_photos.upload_invite_id')
            ->update([
                'audits.tags' => DB::raw("CONCAT('invite:', gallery_photos.upload_invite_id)"),
            ]);
    }

    public function down(): void
    {
        // Irreversible by design: there is no way to tell a backfilled tag from
        // one the model wrote, and clearing both would delete real history.
    }
};
