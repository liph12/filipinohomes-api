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
            $table->id(); // default primary key
            $table->string('code')->unique();
            $table->string('status');
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->decimal('price', 12, 2);
            $table->json('featured_photo')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedBigInteger('clicks')->default(0);
            // Foreign keys
            $table->foreignId('property_id')
                ->constrained('properties')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->foreignId('agent_id')
                ->constrained('agents')
                ->cascadeOnDelete();

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
