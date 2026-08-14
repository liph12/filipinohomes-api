<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for what Leuterio Realty's qualifiers list tells us.
 *
 * Until now the awardee list was pasted in by hand, so the admin showed raw
 * email addresses and nobody could tell who anyone was. The qualifiers endpoint
 * answers that, and it carries more than get-awardee/{email} does: the display
 * name, the team, the sales total, and where LR's own sales-confirmation flow has
 * got to.
 *
 * ⚠️ `display_name` is deliberately NOT split into first_name / last_name.
 *    116 of the 285 qualifiers are couples — "Jo-ann and Albert Maranian",
 *    "George Ryan and Crystal Sarmago" — and any split of those is wrong. It is
 *    also a separate column from first_name/last_name because those are owned by
 *    AwardeeService::mapAwardee() from a different endpoint, and two writers on
 *    one column is how a couple silently becomes one person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->string('display_name', 191)->nullable()->after('last_name');

            // Range on the live list is 43.0M – 737.8M, so 15,2 is comfortable.
            $table->decimal('total_sales', 15, 2)->nullable()->after('team');

            // pending | confirmed | null. Shown in the admin; deliberately does
            // NOT gate a send — the organizers want photos collected early, and
            // 142 of 285 were still pending when this shipped.
            $table->string('lr_confirmation_status', 16)->nullable()->after('total_sales');

            // The raw qualifier record: team id, team logo, isleader, datejoined.
            // Keeping it whole means those need no columns of their own, and
            // mirrors what lr_payload already does for the get-awardee response.
            $table->json('qualifier_payload')->nullable()->after('lr_payload');

            // Serves the admin's "LR qualifier vs added by hand" filter, which is
            // the difference between a safe send and an embarrassing one once 285
            // real awardees sit alongside a handful of test addresses.
            $table->index(['natcon_event_id', 'source'], 'natcon_recip_event_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_recipients', function (Blueprint $table) {
            $table->dropIndex('natcon_recip_event_source_idx');
            $table->dropColumn([
                'display_name',
                'total_sales',
                'lr_confirmation_status',
                'qualifier_payload',
            ]);
        });
    }
};
