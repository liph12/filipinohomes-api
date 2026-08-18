<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen login_logs.user_agent from 255 to 512.
 *
 * The default string() width fits a normal browser agent with room to spare and
 * is nowhere near enough for an in-app browser. Facebook and Instagram append a
 * long identification block — FBAN/FBAV/FBBV/FBDV/FBMD/FBSN/FBSV/FBSS/FBID/
 * FBLC/FBOP — that pushes the whole string past 300 characters, and MySQL in
 * strict mode rejects an over-length value rather than trimming it.
 *
 * Because the insert sat unguarded in the middle of four login flows, that
 * rejection surfaced as a failed login, not a missing log row. See
 * LoginLog::record(), which now also truncates so a wider column is a comfort
 * margin rather than the only thing standing between a long agent and a 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->string('user_agent', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Anything already stored above 255 would be cut by MySQL on the way
        // down, so trim first and make the loss deliberate rather than a
        // surprise mid-migration failure.
        DB::table('login_logs')
            ->whereRaw('CHAR_LENGTH(user_agent) > 255')
            ->update(['user_agent' => DB::raw('LEFT(user_agent, 255)')]);

        Schema::table('login_logs', function (Blueprint $table) {
            $table->string('user_agent', 255)->nullable()->change();
        });
    }
};
