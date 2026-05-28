<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized counter for helpful votes on agent reviews. Lets the
 * public list endpoint render the count without aggregating
 * agent_review_helpful_votes on every read. Kept in sync by
 * AgentReviewController::toggleHelpful — +1 on insert, -1 on delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table) {
            $table->unsignedInteger('helpful_count')->default(0)->after('hidden_reason');
        });
    }

    public function down(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table) {
            $table->dropColumn('helpful_count');
        });
    }
};
