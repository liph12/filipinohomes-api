<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-year switch for the announcement reaction bar.
 *
 * On the event row rather than in config for the usual reason: it is a property
 * of a convention, so 2027 is an admin edit and not a deploy. It is also the
 * valve — reactions are anonymous, and if a year's counts ever get spammed the
 * bar has to come down in seconds, not at the speed of a release.
 *
 * Defaults true so the existing convention gets the feature without a data fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->boolean('reactions_enabled')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->dropColumn('reactions_enabled');
        });
    }
};
