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
        Schema::create('property_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bedroom_count')->default(0);
            $table->unsignedInteger('bathroom_count')->default(0);
            $table->unsignedInteger('garage_count')->default(0);
            $table->decimal('lot_area');   
            $table->decimal('floor_area'); 
            $table->foreignId('property_subtype_id')
                ->constrained('property_subtypes')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_attributes');
    }
};
