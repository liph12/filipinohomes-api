<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->timestamp('last_notified_at')->nullable()->after('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->dropColumn('last_notified_at');
        });
    }
};
