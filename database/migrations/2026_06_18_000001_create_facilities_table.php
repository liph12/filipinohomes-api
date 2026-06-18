<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated registry of high-search-value landmarks (malls, hospitals, schools…)
 * for the "near {facility}" programmatic-SEO pages. One canonical row per
 * landmark — deliberately NOT auto-extracted from listing data (which is sparse
 * and un-dedupable). Coordinates are filled by `facilities:geocode-missing`;
 * a listing is "near" a facility when its property coords fall within a radius
 * of (lat, lng).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');               // canonical, e.g. "SM City Cebu"
            $table->string('slug')->unique();      // e.g. "sm-city-cebu"
            $table->string('category');            // mall | hospital | school | clinic | ...
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
