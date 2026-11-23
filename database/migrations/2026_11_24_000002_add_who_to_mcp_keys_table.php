<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who made an MCP key and who switched it off, now that admins do it from the dashboard
// (System Settings > Claude access). Null when it was done with the artisan commands.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_keys', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
