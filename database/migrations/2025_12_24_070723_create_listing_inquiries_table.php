<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                ->constrained('listings')
                ->cascadeOnDelete();
            $table->foreignId('client_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('agent_id')
                ->constrained('agents')
                ->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'closed'])->default('pending');
            $table->timestamps();

            // One inquiry per client per listing
            $table->unique(['listing_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_inquiries');
    }
};