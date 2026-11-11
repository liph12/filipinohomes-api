<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Who signed in between these dates" — the Executive Report asks it for
     * agents, clients and returning clients, and the Business Pulse already
     * does. login_logs was only indexed on user_id, so every one of those reads
     * scanned the whole table. (logged_in_at, user_id) answers them from the
     * index alone: range on the first column, the id it selects on the second.
     *
     * Guarded so it's a no-op where the index was added by hand first.
     */
    public function up(): void
    {
        if (Schema::hasIndex('login_logs', 'login_logs_logged_in_at_user_id_index')) {
            return;
        }

        Schema::table('login_logs', function (Blueprint $table) {
            $table->index(['logged_in_at', 'user_id'], 'login_logs_logged_in_at_user_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('login_logs', 'login_logs_logged_in_at_user_id_index')) {
            return;
        }

        Schema::table('login_logs', function (Blueprint $table) {
            $table->dropIndex('login_logs_logged_in_at_user_id_index');
        });
    }
};
