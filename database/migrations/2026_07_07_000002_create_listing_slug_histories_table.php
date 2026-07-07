<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Former listing slugs → current listing, so a URL that was ever indexed or
 * shared keeps resolving (the frontend 301s it to the listing's current
 * slug) instead of 404ing. Rows are written automatically by the Listing
 * `updating` observer whenever a slug actually changes — which is now RARE:
 * ListingService no longer regenerates slugs on title edits (URLs are
 * permanent identity; titles are presentation). Same role as the facility
 * `former_slugs` rebrand mechanism.
 *
 * `slug` is UNIQUE: an old slug points at wherever it most recently
 * belonged (upsert on conflict).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_slug_histories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->unsignedBigInteger('listing_id')->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_slug_histories');
    }
};
