<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the invite waves are split.
 *
 * The organizers send in sales tiers — the top band first, the rest a few days
 * later — and the number that divides them is chosen per convention rather than
 * fixed. It lives here, on the event, and not in config or in the browser,
 * because it is campaign POLICY: it decides who gets emailed this week, it has
 * to read the same for everyone who opens the admin, and it belongs to one
 * convention year exactly the way the photo deadline and the reminder offsets
 * already do.
 *
 * Nullable, falling back to config('natcon.sales_breakpoint'), so every existing
 * event keeps working before anyone sets one.
 *
 * There is deliberately no lower bound stored. Everything imported is already
 * above LR's own floor — their `limit` parameter is a sales minimum, not a row
 * cap — so "the lower band" is simply everything below this number, and that
 * stays correct if LR ever moves their floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->decimal('sales_breakpoint', 15, 2)->nullable()->after('reminder_offsets');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->dropColumn('sales_breakpoint');
        });
    }
};
