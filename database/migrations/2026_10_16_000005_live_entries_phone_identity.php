<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The contest form was cut to what an older agent can fill in one go: first name, last name, MOBILE
 * NUMBER. The phone becomes the entry's identity (one entry per number per convention; re-entering
 * updates it) and the email turns optional. Both uniques allow NULLs, so an entry with only one of
 * the two still dedupes on what it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_live_entries', function (Blueprint $table) {
            $table->string('email', 191)->nullable()->change();
            $table->unique(['natcon_event_id', 'phone'], 'natcon_live_entries_event_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_live_entries', function (Blueprint $table) {
            $table->dropUnique('natcon_live_entries_event_phone_unique');
            $table->string('email', 191)->nullable(false)->change();
        });
    }
};
