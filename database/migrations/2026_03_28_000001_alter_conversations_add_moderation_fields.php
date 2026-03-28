<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Expand status enum: add 'pending', 'accepted', 'rejected'
        DB::statement("ALTER TABLE conversations MODIFY COLUMN status ENUM('active','closed','pending','accepted','rejected') NOT NULL DEFAULT 'active'");

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_user_id')->nullable()->after('status');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('agent_user_id');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->foreign('agent_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        // 2. Migrate existing data: active → accepted (they were implicitly approved)
        DB::table('conversations')->where('status', 'active')->update(['status' => 'accepted']);

        // 3. Set agent_user_id on existing listing conversations
        // For each chat of type 'listing', find the conversation user who is NOT the chat creator
        DB::statement("
            UPDATE conversations c
            JOIN chats ch ON c.chat_id = ch.id
            JOIN conversation_users cu ON cu.conversation_id = c.id AND cu.user_id != ch.user_id
            SET c.agent_user_id = cu.user_id
            WHERE ch.type = 'listing'
        ");
    }

    public function down(): void
    {
        // Revert accepted → active
        DB::table('conversations')->where('status', 'accepted')->update(['status' => 'active']);
        DB::table('conversations')->where('status', 'pending')->update(['status' => 'active']);
        DB::table('conversations')->where('status', 'rejected')->update(['status' => 'closed']);

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['agent_user_id']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['agent_user_id', 'reviewed_by', 'reviewed_at']);
        });

        DB::statement("ALTER TABLE conversations MODIFY COLUMN status ENUM('active','closed') NOT NULL DEFAULT 'active'");
    }
};
