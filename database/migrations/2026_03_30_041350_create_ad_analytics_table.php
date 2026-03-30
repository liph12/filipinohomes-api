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
        Schema::create('ad_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_id')->constrained()->cascadeOnDelete();
            $table->string('country')->default('PH');
            $table->string('state')->default('Manila');
            $table->string('city')->default('Manila City');
            $table->string('created_hour_at')->default(null);
            $table->string('created_date_at')->default(null);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('total_impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('total_clicks')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_analytics');
    }
};
