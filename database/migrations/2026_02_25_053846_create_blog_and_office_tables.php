<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBlogAndOfficeTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Blog Categories Table
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_term_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->timestamps();
        });

        // Posts Table
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->longText('content');
            $table->longText('excerpt')->nullable();
            $table->string('featured_image', 255)->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->foreignId('category_id')
                  ->constrained('blog_categories')
                  ->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Offices Table
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('title', 255)->nullable();
            $table->string('contact', 255)->nullable();
            $table->string('phone', 255)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offices');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('blog_categories');
    }
}