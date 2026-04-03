<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('loop_duration')->default(5)->after('ends_at');
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->datetime('starts_at')->nullable()->after('status');
            $table->datetime('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropColumn('loop_duration');
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
