<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-participant "Delete Permanently" state for chats. Extends the
 * archive/trash state machine introduced by
 * 2026_05_23_120000_add_archive_and_trash_to_conversation_users.
 *
 * New column on the conversation_users pivot:
 *   purged_at — when the viewer hit "Delete Permanently" from the Trash
 *               view. Once set, the chat is excluded from every view
 *               (inbox / archived / trash) for that viewer. The row
 *               stays in the database for audit and admin recovery.
 *
 * State machine (full):
 *   archived_at NULL + removed_at NULL + purged_at NULL → Inbox (default)
 *   archived_at NOT NULL + removed_at NULL + purged_at NULL → Archived
 *   removed_at NOT NULL + purged_at NULL → Trash
 *   purged_at NOT NULL → completely hidden from the viewer (but in DB)
 *
 * Restore from Trash clears archived_at + removed_at (not purged_at —
 * once purged, only an admin tool would un-purge a row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('removed_at');
            $table->index(['user_id', 'purged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'purged_at']);
            $table->dropColumn('purged_at');
        });
    }
};
