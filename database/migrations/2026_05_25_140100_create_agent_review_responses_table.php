<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One public response per review by the rated agent. Upsert semantics
 * (one row per agent_review_id enforced by the unique constraint) so a
 * resubmit overwrites the previous response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_review_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_review_id')->unique()
                ->constrained('agent_reviews')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_review_responses');
    }
};
