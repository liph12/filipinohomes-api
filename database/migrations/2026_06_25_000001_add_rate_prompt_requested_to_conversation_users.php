<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-participant "the assigned agent asked this client to leave a
 * review" flag. Stamped on the CLIENT's conversation_users row when the
 * agent (or a moderator) clicks "Ask client for a review" in the chat
 * details panel. Surfaces the inline rate prompt in the client's thread
 * even when the 24h-no-reply nudge wouldn't otherwise fire. Cleared
 * implicitly once the client submits a review (threadVisibility gates
 * on an existing review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->timestamp('rate_prompt_requested_at')
                ->nullable()
                ->after('rate_prompt_shown_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->dropColumn('rate_prompt_requested_at');
        });
    }
};
