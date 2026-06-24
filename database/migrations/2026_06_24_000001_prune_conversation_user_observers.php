<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time cleanup of conversation_users bloat.
     *
     * ConversationController::markRead used to syncWithoutDetaching() the
     * viewer on every open, so every admin / team leader / browsing agent who
     * ever looked at a thread became a permanent "participant". Some
     * conversations grew to a dozen-plus members, and the frontend fanned
     * every realtime event (message + typing) out to all of them — causing
     * duplicate delivery and excess socket traffic. markRead no longer
     * auto-attaches observers; this prunes the rows that already leaked in.
     *
     * Rows KEPT (genuine participants):
     *   - the chat owner (chats.user_id)
     *   - the assigned agent (conversations.agent_user_id)
     *   - the agent-direct peer (chats.type = 'agent' AND chats.type_id)
     *   - anyone who has actually sent a message in the conversation
     */
    public function up(): void
    {
        DB::statement("
            DELETE cu FROM conversation_users cu
            INNER JOIN conversations c ON c.id = cu.conversation_id
            INNER JOIN chats ch ON ch.id = c.chat_id
            WHERE cu.user_id <> ch.user_id
              AND (c.agent_user_id IS NULL OR cu.user_id <> c.agent_user_id)
              AND NOT (ch.type = 'agent' AND cu.user_id = ch.type_id)
              AND NOT EXISTS (
                  SELECT 1 FROM messages m
                  WHERE m.conversation_id = cu.conversation_id
                    AND m.user_id = cu.user_id
              )
        ");
    }

    /**
     * Irreversible. The pruned rows were observers whose pivot state
     * (last_read_at / archive flags) was incidental to merely having viewed
     * the thread — there is nothing first-class to restore.
     */
    public function down(): void
    {
        // no-op
    }
};
