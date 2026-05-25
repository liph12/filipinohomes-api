<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolled-up rating columns on the agents table. Recomputed by
 * AgentRatingRollupService after any agent_reviews row change. The
 * decimal(3,2) shape comfortably holds 0.00 through 5.00; total_reviews
 * is unsigned int.
 *
 * Joined index on (avg_rating, total_reviews) backs the
 * sort_by=highest_rated branch in AgentController::index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->decimal('avg_rating', 3, 2)->nullable()->after('response_metrics_updated_at');
            $table->unsignedInteger('total_reviews')->default(0)->after('avg_rating');
            $table->index(['avg_rating', 'total_reviews']);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['avg_rating', 'total_reviews']);
            $table->dropColumn(['avg_rating', 'total_reviews']);
        });
    }
};
