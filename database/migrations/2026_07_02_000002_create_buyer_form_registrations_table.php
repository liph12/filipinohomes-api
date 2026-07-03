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
        Schema::create('buyer_form_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_form_id')
                ->constrained('buyer_forms')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users');
            $table->string('full_name');
            $table->string('email');
            $table->string('home_address')->nullable();
            $table->timestamps();

            $table->unique(['buyer_form_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buyer_form_registrations');
    }
};
