<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A committee whose "people" are suppliers (the staging company, the AV
 * crew) shows names set as type instead of portraits. A silhouette on such a
 * card reads as "photo missing"; a nameplate reads as intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_organizer_committees', function (Blueprint $table) {
            $table->boolean('names_only')->default(false)->after('hide_members_label');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_organizer_committees', function (Blueprint $table) {
            $table->dropColumn('names_only');
        });
    }
};
