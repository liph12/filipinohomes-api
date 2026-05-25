<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-participant "rate prompt already shown on this conversation" flag.
 * Set the first time the inline RateAgent card surfaces for a viewer on
 * a given conversation (or when they dismiss it without submitting).
 * Prevents re-showing the card after a dismiss, even across sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->timestamp('rate_prompt_shown_at')->nullable()->after('purged_at');
            $table->index(['user_id', 'rate_prompt_shown_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversation_users', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'rate_prompt_shown_at']);
            $table->dropColumn('rate_prompt_shown_at');
        });
    }
};
