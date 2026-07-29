<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review queue for the nationwide facility scanner (facilities:scan-candidates):
 * named malls / universities+colleges / hospitals discovered on OpenStreetMap
 * inside every city where FilipinoHomes has listings, each scored with the
 * SAME 1.5km radius query as the nightly facility compute. Candidates are
 * NEVER auto-added — an admin approves (creates a Facility + recompute) or
 * dismisses from the SEO Manage → Candidates tile. Rescans upsert on
 * (source, osm_type, osm_id) and never touch `status`, so dismissed stays
 * dismissed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('osm');
            $table->string('osm_type', 16);              // node | way | relation
            $table->unsignedBigInteger('osm_id');
            $table->string('name');
            $table->string('category');                   // mall | school | hospital
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('city')->nullable();           // scan-area city (denormalized)
            $table->string('province')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            // Projected-count score (FacilityCountService::previewCounts).
            $table->unsignedInteger('max_total')->default(0);
            $table->boolean('clears_floor')->default(false);
            $table->json('cohorts')->nullable();
            $table->string('status')->default('pending'); // pending | approved | dismissed
            // Existing facility of the same category within ~250m (or slug hit)
            // — excluded from the pending queue by default.
            $table->unsignedBigInteger('matched_facility_id')->nullable();
            $table->unsignedBigInteger('approved_facility_id')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->unique(['source', 'osm_type', 'osm_id'], 'fc_source_unique');
            $table->index(['status', 'clears_floor']);
            $table->index('category');
            $table->index('max_total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_candidates');
    }
};
