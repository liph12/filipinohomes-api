<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('page_builder', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->json('seo_tags')->nullable();
            $table->text('description')->nullable();
            $table->json('banner')->nullable();
            $table->json('gallery')->nullable();
            $table->json('video_url')->nullable();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->foreignId('agent_id')
                ->constrained('agents')
                ->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_builder');
    }
};
