<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages') && !$this->indexExists('messages', 'messages_conversation_id_created_at_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->index(['conversation_id', 'created_at'], 'messages_conversation_id_created_at_index');
            });
        }

        if (Schema::hasTable('conversations') && !$this->indexExists('conversations', 'conversations_chat_id_status_index')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->index(['chat_id', 'status'], 'conversations_chat_id_status_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('messages') && $this->indexExists('messages', 'messages_conversation_id_created_at_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropIndex('messages_conversation_id_created_at_index');
            });
        }

        if (Schema::hasTable('conversations') && $this->indexExists('conversations', 'conversations_chat_id_status_index')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropIndex('conversations_chat_id_status_index');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );
        return !empty($rows);
    }
};
