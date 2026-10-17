<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some cards want no word at all above the smaller portraits — just the
 * faces under the head. A flag rather than an empty members_label, because
 * Laravel turns empty strings into null, and null already means "default".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_organizer_committees', function (Blueprint $table) {
            $table->boolean('hide_members_label')->default(false)->after('members_label');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_organizer_committees', function (Blueprint $table) {
            $table->dropColumn('hide_members_label');
        });
    }
};
