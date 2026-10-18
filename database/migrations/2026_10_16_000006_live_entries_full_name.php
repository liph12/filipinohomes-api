<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The contest form asks for ONE name box (full name), mobile and email — nothing else. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_live_entries', function (Blueprint $table) {
            $table->string('full_name', 191)->nullable()->after('natcon_event_id');
            $table->string('first_name', 100)->nullable()->change();
            $table->string('last_name', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('natcon_live_entries', function (Blueprint $table) {
            $table->dropColumn('full_name');
        });
    }
};
