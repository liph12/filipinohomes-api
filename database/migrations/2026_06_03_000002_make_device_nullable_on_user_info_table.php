<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The original migration used ->default(null), but MySQL can't put a
        // default on a JSON column, so `device` ended up NOT NULL with no
        // default — inserting a user_info row without it (e.g. dev login)
        // fails with SQLSTATE[HY000] 1364. Make it properly nullable.
        Schema::table('user_info', function (Blueprint $table) {
            $table->json('device')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_info', function (Blueprint $table) {
            $table->json('device')->nullable(false)->change();
        });
    }
};
