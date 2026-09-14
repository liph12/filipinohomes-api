<?php

use App\Models\GalleryAlbum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convention albums become URL-addressable, and learn which half of the
 * gallery they belong to.
 *
 * ─── Slugs ──────────────────────────────────────────────────────────────────
 *
 * `slug` was written for PUBLIC albums only (/albums/{slug}); a convention
 * album carried NULL because "the convention page is the only doorway". That
 * doorway was a single page holding its album tree in React state, so opening
 * an album changed nothing in the address bar — nobody could share, bookmark
 * or link one, and Back left the gallery entirely.
 *
 * /natcon/gallery/{slug}/{sub-slug} fixes that, and it needs a real slug per
 * album. Backfilled here rather than lazily, because the public page resolves
 * an album BY slug: an album that has never been edited since this deploy
 * would otherwise be unreachable.
 *
 * Slugs stay globally unique (the existing single-column unique index), so
 * the last path segment alone identifies an album — the same contract the
 * public albums rely on. Two conventions that both hold a "Day 1" therefore
 * get `day-1` and `day-1-2`; uniqueSlug() already does that, and a suffix on
 * the newer album is a far smaller price than a per-scope index change (whose
 * FK-backing-index hazard is written up in
 * 2026_09_05_000001_scope_gallery_album_names_to_parent.php).
 *
 * ─── Section + date ─────────────────────────────────────────────────────────
 *
 * A convention gallery holds two very different things: the convention
 * itself, and the months of preparation before it (coordination meetings,
 * staff meetings, packing). Shown in one undifferentiated grid, the planning
 * photos read as the event — which is exactly how the 2026 gallery looked.
 *
 * `section` splits them: `event` leads the public page in large cards,
 * `prep` follows underneath. `album_date` is the day a convention-day album
 * covers, so the card can print its date and light up while that day is
 * actually happening.
 *
 * Default `event`, because once the convention starts that is what almost
 * every new album is; the handful of planning albums get flipped in the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gallery_albums', function (Blueprint $table) {
            // 16 chars, not an enum: adding a third section later must not be
            // a table rebuild. Validated at the controller (`in:event,prep`).
            $table->string('section', 16)->default('event')->after('name');
            $table->date('album_date')->nullable()->after('section');
        });

        // Oldest first, so the album that has been around longest keeps the
        // clean slug and any later namesake takes the -2. uniqueSlug() checks
        // the whole table on every call, including rows written by this loop.
        GalleryAlbum::whereNull('slug')->orderBy('id')->get()->each(function (GalleryAlbum $album) {
            // Straight to the query builder: no audit row, no touched
            // timestamps — this is a backfill, not an edit someone made.
            DB::table('gallery_albums')
                ->where('id', $album->id)
                ->update(['slug' => GalleryAlbum::uniqueSlug($album->name, $album->id)]);
        });
    }

    public function down(): void
    {
        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->dropColumn(['section', 'album_date']);
        });

        // Back to "convention albums carry no slug". Only the convention rows,
        // so the public albums keep the URLs their shared links hold.
        DB::table('gallery_albums')->whereNotNull('natcon_event_id')->update(['slug' => null]);
    }
};
