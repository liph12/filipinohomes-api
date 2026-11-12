<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inquiry Filipinohomes Assistant (`inquiries:ai-reply`, App\Services\Inquiry\InquiryAiReplyService).
 *
 * - messages.automated: true on messages the assistant posted, so the chat UI
 *   labels them and reply-based stats ("unreplied", "anyone replied") don't
 *   count them as a human answer.
 * - inquiry_ai_replies: one row per conversation per stage ('ack' right after
 *   approval, 'escalation' when the agent still hasn't replied). The unique key
 *   is also the claim: the row is inserted BEFORE the message is posted, so two
 *   schedulers (EC2 + Laravel Cloud) can never send the same stage twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'automated')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->boolean('automated')->default(false)->after('status');
            });
        }

        if (! Schema::hasTable('inquiry_ai_replies')) {
            Schema::create('inquiry_ai_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->string('stage', 16); // ack | escalation
                $table->string('status', 16)->default('claimed'); // claimed | sent | failed
                $table->unsignedBigInteger('message_id')->nullable();
                $table->string('source', 16)->nullable(); // ai | template
                $table->unsignedInteger('tokens')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'stage']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_ai_replies');
        if (Schema::hasColumn('messages', 'automated')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('automated');
            });
        }
    }
};
