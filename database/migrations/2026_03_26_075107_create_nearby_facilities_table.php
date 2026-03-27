<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nearby_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')
                ->constrained('properties')
                ->cascadeOnDelete();
            $table->json('school')->nullable();
            $table->json('hospital')->nullable();
            $table->json('clinic')->nullable();
            $table->json('pharmacy')->nullable();
            $table->json('fire_station')->nullable();
            $table->json('police_station')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nearby_facilities');
    }
};
