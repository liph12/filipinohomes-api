<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cash prizes are sent through GCash: the registrant's GCash number, its own field. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('natcon_live_entries', 'gcash')) {
            Schema::table('natcon_live_entries', function (Blueprint $table) {
                $table->string('gcash', 32)->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('natcon_live_entries', 'gcash')) {
            Schema::table('natcon_live_entries', fn (Blueprint $table) => $table->dropColumn('gcash'));
        }
    }
};
