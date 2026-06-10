<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // Where the event came from. 'mobile' (the Expo app) vs 'web'
            // (admin/agent browser). Derived in AuditAuthService from the
            // device_name the app sends, then the User-Agent as a fallback.
            // Nullable so historical rows and non-auth audits stay valid.
            $table->string('client', 16)->nullable()->after('source');
            // OS family for mobile clients: 'android' | 'ios' (null for web).
            $table->string('device_platform', 16)->nullable()->after('client');

            // Lets the activity-log feed filter "mobile auth events" without a
            // full scan (e.g. recent mobile logins on the Mobile Statistics page).
            $table->index(['category', 'client'], 'audits_category_client_index');
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex('audits_category_client_index');
            $table->dropColumn(['client', 'device_platform']);
        });
    }
};
