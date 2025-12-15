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
        Schema::create('listing_conversations', function (Blueprint $table) {
            $table->id(); // default primary key
            $table->string('messages'); // inquiry messages
            $table->string('client_status')->nullable();
            $table->string('agent_status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_conversations');
    }
};
