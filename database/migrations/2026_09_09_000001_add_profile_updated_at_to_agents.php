<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the person last SAVED their profile through the profile-edit form.
 *
 * agents.updated_at cannot stand in for this: the row is also touched by the
 * response-metric recompute, LR syncs and status changes, so it moves for
 * active agents whether or not they ever looked at their profile. The
 * dashboard's "is your profile up to date?" reminder needs the human event.
 * Null = never saved through the form (legacy/imported rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->timestamp('profile_updated_at')->nullable()->after('geo_location');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('profile_updated_at');
        });
    }
};
