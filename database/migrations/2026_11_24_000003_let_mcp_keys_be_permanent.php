<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A key can now be made permanent from the dashboard (System Settings > Claude access): it has
// no end date, so `expires_at` may be empty, and it works until someone revokes it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_keys', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // The column can't be empty again, and a rollback must not leave a key with no end:
        // a permanent key ends now.
        DB::table('mcp_keys')->whereNull('expires_at')->update(['expires_at' => now()]);

        Schema::table('mcp_keys', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
