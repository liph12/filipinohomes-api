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
           Schema::create('listing_inquiries', function (Blueprint $table) {
            $table->id(); // default primary key
            $table->string('status');
            $table->foreignId('client_id')
                ->constrained('users') // assuming clients are users
                ->cascadeOnDelete();
            $table->foreignId('listing_id')
                ->constrained('listings')
                ->cascadeOnDelete();
            $table->foreignId('conversation_id')
                ->nullable() // optional if threaded
                ->constrained('listing_conversations') 
                ->nullOnDelete(); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_inquiries');
    }
};
