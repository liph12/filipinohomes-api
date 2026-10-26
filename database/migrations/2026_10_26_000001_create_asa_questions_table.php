<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every question put to ASA-GPT, with how it was understood and how it ended — the admin
 * "What people ask ASA" view reads this. One row per question; the search half of a listing or
 * agent question updates the row's outcome. No message content beyond the question itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asa_questions', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 120)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('question');
            $table->string('intent', 24)->index();
            $table->string('place', 120)->nullable();
            // answered | no_results | no_data | error
            $table->string('outcome', 24)->default('answered')->index();
            $table->unsignedInteger('ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asa_questions');
    }
};
