<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a committee card calls the people under its head. The chart said
 * "Assistants" for everyone, but a Core Leader card lists "Members", a
 * secretariat lists "Staff", and so on — the admin now names the group per
 * card. Null means the default ("Assistant" / "Assistants" by count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_organizer_committees', function (Blueprint $table) {
            $table->string('members_label', 60)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_organizer_committees', function (Blueprint $table) {
            $table->dropColumn('members_label');
        });
    }
};
