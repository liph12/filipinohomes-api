<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shopee-style anonymous reviews: the reviewer's identity stays in the row
// (moderation + one-review-per-pair still work) but AgentReviewResource masks
// it for everyone except the reviewer and admins.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table) {
            $table->boolean('is_anonymous')->default(false)->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table) {
            $table->dropColumn('is_anonymous');
        });
    }
};
