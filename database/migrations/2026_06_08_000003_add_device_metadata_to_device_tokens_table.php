<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            // Device metadata for the broadcast analytics screen (fleet breakdown
            // by OS version / model / app build). Populated on token register;
            // existing rows stay null until the device next re-registers.
            $table->string('os_version', 32)->nullable()->after('platform');
            $table->string('device_model', 128)->nullable()->after('os_version');
            $table->string('app_version', 32)->nullable()->after('device_model');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn(['os_version', 'device_model', 'app_version']);
        });
    }
};
