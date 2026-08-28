<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The awardee's province, so the admin list can be filtered by it and an
 * export can say which province it covers.
 *
 * Named `state` because that is what the LR qualifiers API calls it
 * (`member[0].state`, e.g. "Davao del Sur") — keeping the column, the API
 * field and the upstream key spelled the same way is worth more than the
 * label being wrong for the Philippines. The admin UI says "Province".
 *
 * ─── The backfill ───────────────────────────────────────────────────────────
 *
 * Every row synced from LR already carries this value: the whole qualifier
 * record is stored verbatim in `qualifier_payload`, and the sync simply never
 * read `state` out of it. So existing awardees are filled in from the JSON we
 * already hold rather than by re-fetching 285 records from LR — the data is
 * identical and the request is pure waste.
 *
 * Rows added by hand have no qualifier_payload and stay NULL, which is the
 * honest answer; the admin surfaces them as "No province".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->string('state', 191)->nullable()->after('team');
            $table->index(['natcon_event_id', 'state'], 'natcon_recip_event_state_idx');
        });

        // JSON_EXTRACT is MySQL/MariaDB. Wrapped so a different driver (or a
        // payload shaped unexpectedly) can never fail the deploy — an empty
        // column is recoverable with one Sync from LR; a failed migration on
        // api2 is not.
        try {
            DB::statement(<<<'SQL'
                UPDATE natcon_recipients
                   SET state = NULLIF(
                         TRIM(JSON_UNQUOTE(JSON_EXTRACT(qualifier_payload, '$.member[0].state'))),
                         'null'
                       )
                 WHERE qualifier_payload IS NOT NULL
                   AND state IS NULL
            SQL);
        } catch (\Throwable $e) {
            Log::warning('natcon recipients state backfill skipped', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->dropIndex('natcon_recip_event_state_idx');
            $table->dropColumn('state');
        });
    }
};
