<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chats')) return;
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['listing', 'agent', 'blog', 'reel']);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('type_id');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'type_id']);
            $table->index('user_id');
            $table->unique(['type', 'type_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
