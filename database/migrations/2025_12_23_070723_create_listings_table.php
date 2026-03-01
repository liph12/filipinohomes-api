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
       Schema::create('listings', function (Blueprint $table) {
            $table->id(); 
            $table->string('code')->unique()->nullable();
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->decimal('price', 20, 2);
            $table->json('featured_photo')->nullable();
            $table->string('visibility')->default("private");
            $table->boolean('is_featured')->default(false);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->foreignId('property_id')
                ->constrained('properties')
                ->restrictOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

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
        Schema::dropIfExists('listings');
    }
};
