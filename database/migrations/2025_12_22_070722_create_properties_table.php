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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default("active");
            $table->text('address')->nullable();
            $table->json('photos')->nullable();     // array of image paths
            $table->json('amenities')->nullable();  // array of amenities
            $table->text('description')->nullable();
            $table->string('geo_coordinates')->nullable(); 
            $table->boolean('is_project')->default(false);
            $table->foreignId('property_attribute_id')
                ->constrained('property_attributes')
                ->restrictOnDelete();
            $table->foreignId('furnishing_id')
                ->nullable()
                ->constrained('furnishings')
                ->restrictOnDelete();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
