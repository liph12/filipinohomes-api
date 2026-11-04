<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin-only "appear offline" switch. When true the admin is dropped from the
// "Online now" staff list (and the chat presence endpoint) so other staff
// don't see them online. Default false = visible, like everyone else. Lives on
// users (not just admins) so it survives role changes; only ever set by admins.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('hide_online_presence')->default(false)->after('admin_emails_muted');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hide_online_presence');
        });
    }
};
