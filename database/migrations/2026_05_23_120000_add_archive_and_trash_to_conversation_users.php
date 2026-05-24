<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-participant archive + trash state for chats.
 *
 *   archived_at NULL   + removed_at NULL  → Inbox     (default)
 *   archived_at NOTNULL + removed_at NULL → Archived
 *   removed_at NOTNULL                    → Trash
 *
 * Both states are scoped to (user_id, conversation_id) so archiving on one
 * side of the conversation doesn't hide it from the other participant.
 * Indexes are scoped by user_id because every query that uses these
 * columns also constrains by the authenticated viewer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('last_notified_at');
            $table->timestamp('removed_at')->nullable()->after('archived_at');
            $table->index(['user_id', 'archived_at']);
            $table->index(['user_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'removed_at']);
            $table->dropIndex(['user_id', 'archived_at']);
            $table->dropColumn(['removed_at', 'archived_at']);
        });
    }
};
