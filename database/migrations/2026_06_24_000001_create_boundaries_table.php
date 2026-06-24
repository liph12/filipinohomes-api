<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administrative boundary polygons (geoBoundaries PH ADM3 = city/municipality,
 * ADM4 = barangay) for the admin all-listings map. Geometry is stored as
 * SRID 0 (planar lat/lng) to match the existing ST_GeomFromText polygon filter
 * and because ST_Simplify only works on SRID 0 in MySQL (it errors on 4326).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->enum('level', ['city', 'barangay']);
            $table->string('name');
            $table->string('parent_name')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('barangay_id')->nullable();
            $table->timestamps();

            $table->index('level');
            $table->index(['level', 'city_id']);
            $table->index('barangay_id');
        });

        // geom must be NOT NULL for a SPATIAL INDEX. The fluent geometry() builder
        // makes it nullable, so add it via raw DDL. No `srid` clause → defaults to
        // SRID 0, matching the listing polygon filter.
        DB::statement('ALTER TABLE boundaries ADD COLUMN geom GEOMETRY NOT NULL');
        DB::statement('ALTER TABLE boundaries ADD SPATIAL INDEX boundaries_geom_spatial (geom)');

        Schema::table('boundaries', function (Blueprint $table) {
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('barangay_id')->references('id')->on('barangays')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boundaries');
    }
};
