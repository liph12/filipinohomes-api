<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Album names become unique among SIBLINGS rather than across a whole gallery.
 *
 * `UNIQUE(natcon_event_id, name)` said no two albums anywhere in one
 * convention may share a name. That is wrong for a tree, and it bites hardest
 * exactly where the gallery is used hardest: two hired photographers, each
 * fenced to their own album by a scoped upload invite, both making "Day 1".
 * The second one silently became "Day 1 (2)" because of an album they cannot
 * see and were never told about — and a rename to the same name failed with a
 * message naming that invisible album.
 *
 * "Day 1" inside "Awarding" and "Day 1" inside "Registration" are different
 * albums. Only siblings genuinely collide, and the path already disambiguates
 * everything else.
 *
 * ─── Order is load-bearing ──────────────────────────────────────────────────
 *
 * ⚠️ The old index is the ONLY index on `natcon_event_id`, so it is what backs
 *    `natcon_gallery_albums_natcon_event_id_foreign`. Dropping it first fails
 *    with MySQL errno 1553 ("needed in a foreign key constraint"). The
 *    replacement is created FIRST and also leads with `natcon_event_id`, so it
 *    takes over as the FK's backing index before the old one goes.
 *
 *    Same hazard the sponsors table hit; same fix.
 */
return new class extends Migration
{
    private const OLD = 'natcon_gallery_albums_event_name_uq';

    private const NEW = 'gallery_albums_event_parent_name_uq';

    public function up(): void
    {
        // Widening only: any row satisfying (event, name) already satisfies
        // (event, parent, name), so this cannot fail on existing data.
        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->unique(['natcon_event_id', 'parent_id', 'name'], self::NEW);
        });

        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->dropUnique(self::OLD);
        });
    }

    public function down(): void
    {
        /**
         * Narrowing, so this CAN fail — by design. Once photographers have made
         * "Day 1" under two different parents the old constraint is no longer
         * satisfiable, and silently dropping one of their albums to force it
         * would be worse than refusing.
         *
         * Reported as a readable list rather than a raw 1062, because the
         * person reading it has to go and rename something.
         */
        $clashes = DB::table('gallery_albums')
            ->selectRaw('natcon_event_id, name, COUNT(*) n')
            ->groupBy('natcon_event_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($clashes->isNotEmpty()) {
            $names = $clashes->take(10)->map(fn ($c) => "\"{$c->name}\" ×{$c->n}")->implode(', ');

            throw new RuntimeException(
                'Cannot restore the convention-wide unique album name: these names are now used by '
                . "more than one album in the same gallery — {$names}. Rename them first."
            );
        }

        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->unique(['natcon_event_id', 'name'], self::OLD);
        });

        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->dropUnique(self::NEW);
        });
    }
};
