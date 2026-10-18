<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Going live is a separate, manual step from creating a contest. A contest is a DRAFT until the admin
 * presses Go live (`started_at`), LIVE until they press End (`ended_at`), then ENDED. Any number of
 * drafts and ended contests may exist; only one may be live. Existing rows were live from creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_live_contests', fn (Blueprint $t) => $t->timestamp('started_at')->nullable()->after('description'));
        DB::table('natcon_live_contests')->whereNull('started_at')->update(['started_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('natcon_live_contests', fn (Blueprint $t) => $t->dropColumn('started_at'));
    }
};
