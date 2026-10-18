<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A contest's own words — the prize, the mechanics — shown in the Register modal under the headline. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_live_contests', fn (Blueprint $t) => $t->text('description')->nullable()->after('title'));
    }

    public function down(): void
    {
        Schema::table('natcon_live_contests', fn (Blueprint $t) => $t->dropColumn('description'));
    }
};
