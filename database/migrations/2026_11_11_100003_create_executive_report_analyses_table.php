<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Executive Report's AI analyses, one row per generation.
     *
     * In the database, not the cache, on purpose: production's cache is the
     * file store, per instance and wiped on every deploy. An analysis kept
     * there would be thrown away by the next release and quietly re-billed to
     * OpenAI the next time an admin opened the same dates. Reopening a range
     * must read this table and never call the model.
     *
     * `facts` is the exact snapshot the model was given, so a saved analysis
     * always renders the numbers it was written about, and `facts_hash` tells
     * the page when today's numbers have moved on from them.
     */
    public function up(): void
    {
        Schema::create('executive_report_analyses', function (Blueprint $table) {
            $table->id();
            $table->date('date_start');
            $table->date('date_end');
            $table->char('facts_hash', 64);
            $table->json('facts');
            $table->json('result');
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['date_start', 'date_end', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_report_analyses');
    }
};
