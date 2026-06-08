<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            // Links a fanned-out feed row back to its source announcement so the
            // analytics screen can count reads with a plain indexed query
            // instead of probing the data JSON. Null for ordinary notifications.
            $table->foreignId('announcement_id')->nullable()->after('user_id')
                ->constrained('announcements')->nullOnDelete();
            $table->index(['announcement_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropForeign(['announcement_id']);
            $table->dropIndex(['announcement_id', 'read_at']);
            $table->dropColumn('announcement_id');
        });
    }
};
