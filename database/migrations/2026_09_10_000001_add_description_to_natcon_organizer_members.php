<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short line under a person's name on the organizers chart — their
 * assignment within the committee ("Entrance", "Registration desk"), the way
 * the team's own bond-paper chart writes it in brackets under each name.
 *
 * Nullable: most heads and many assistants carry the committee title alone.
 * Short on purpose (120): it sits under a 40px portrait on a phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_organizer_members', function (Blueprint $table) {
            $table->string('description', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_organizer_members', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
