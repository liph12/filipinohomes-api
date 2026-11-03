<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Void every outstanding login code.
 *
 * A login code sits in users.verification (6 chars, Str::upper(Str::random(6)))
 * until it's used, and it never expires. Raw User JSON used to carry that
 * column — /client-logins handed every client's pending code to any signed-in
 * account — so a code copied before the column was hidden would still work.
 * Resetting them to 'pending' kills those; anyone mid-login just asks for a
 * new code.
 *
 * Data only — no schema change. The status words ('pending' / 'verified') and
 * NULL are left alone, and it is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('verification')
            ->whereNotIn('verification', ['pending', 'verified'])
            ->whereRaw('LENGTH(verification) = 6')
            ->update(['verification' => 'pending']);
    }

    public function down(): void
    {
        // Irreversible by design: the voided codes are gone, and restoring
        // them would reopen exactly what this closes.
    }
};
