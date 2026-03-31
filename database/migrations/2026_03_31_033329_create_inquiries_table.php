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
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default(null);
            $table->string('email')->default(null);
            $table->string('message')->default(null);
            $table->json('device')->default(null);
            $table->string('country')->default('Unknown');
            $table->string('state')->default('Unknown');
            $table->string('city')->default('Unknown');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
