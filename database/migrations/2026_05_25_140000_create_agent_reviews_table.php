<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent review storage — one row per (client, agent) pair, editable for
 * 7 days after each save. The conversation that fired the engagement
 * gate is captured (nullable so a review can outlive its originating
 * conversation if the conversation is later hard-deleted).
 *
 * status:
 *   visible — public on the agent profile, counts toward aggregate
 *   hidden  — admin-suppressed; not public, not counted
 *   flagged — held for admin review (anti-abuse); not public, not counted
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()
                ->constrained('conversations')->nullOnDelete();
            $table->tinyInteger('overall_rating')->unsigned(); // 1..5
            $table->json('tags')->nullable();
            $table->text('comment')->nullable();
            $table->enum('status', ['visible', 'hidden', 'flagged'])->default('visible');
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable();
            $table->text('hidden_reason')->nullable();
            $table->timestamp('edit_window_ends_at')->nullable();
            $table->timestamps();

            $table->unique(['client_user_id', 'agent_user_id']);
            $table->index(['agent_user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_reviews');
    }
};
