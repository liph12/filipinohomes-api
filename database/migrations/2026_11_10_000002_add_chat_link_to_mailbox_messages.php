<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link inquiry emails in the Mailbox index to their inquiry (chats.id), so the
 * Mailbox can show the inquiry's live approval status ("To be approved",
 * "Approved"…). Found in the X-FH-Chat-Id header (new emails) or the
 * /inbox/{slug}-{chatId} link in the body (older emails, read once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_id')->nullable()->after('category')->index();
            $table->boolean('chat_checked')->default(false)->after('chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropIndex(['chat_id']);
            $table->dropColumn(['chat_id', 'chat_checked']);
        });
    }
};
