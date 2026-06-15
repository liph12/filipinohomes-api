<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * First-touch acquisition link. The web carries a persistent `visitor_id`
     * in localStorage (same id sent to /track/visit). We stamp it on the user
     * at signup so a client can be tied back to the channel of their earliest
     * anonymous visit — see TrafficSourceService::channels(). Nullable because
     * historical users have none; indexed for the visits join.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('visitor_id', 64)->nullable()->index()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['visitor_id']);
            $table->dropColumn('visitor_id');
        });
    }
};
