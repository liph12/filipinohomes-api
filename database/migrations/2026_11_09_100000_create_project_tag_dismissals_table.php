<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not this project": an admin's word that a listing whose title names a development is not in it,
 * so the project tagging check stops showing that pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tag_dismissals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('listing_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->unique(['listing_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tag_dismissals');
    }
};
