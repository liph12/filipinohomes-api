<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user "helpful" votes on agent reviews. Visitors can mark a
 * review as helpful to give other readers a social signal about
 * which reviews are most informative. One vote per (review, user)
 * pair — the controller toggles on/off rather than stacking votes.
 *
 * The denormalized counter lives on agent_reviews.helpful_count so
 * public list reads don't need to aggregate this table on every
 * page load. The counter is kept in sync by the toggleHelpful
 * endpoint (insert → +1, delete → -1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_review_helpful_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_review_id')
                ->constrained('agent_reviews')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            // One vote per (review, user). Same shape the controller's
            // toggle logic depends on for the "find existing" lookup.
            $table->unique(['agent_review_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_review_helpful_votes');
    }
};
