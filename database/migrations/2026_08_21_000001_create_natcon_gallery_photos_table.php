<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event photos shown on the public NATCON landing page — the highlights grid,
 * not the awardee headshots (those live in natcon_photo_submissions and are
 * never public).
 *
 * Tied to natcon_events like sponsors, and for the same reason: the photos are
 * per convention, and next year's page must not inherit this year's gallery.
 *
 * ONE status column carries both visibility and the delete lifecycle
 * (active | hidden | deleted) instead of an is_published flag plus SoftDeletes.
 * Sponsors already walked this road: is_published duplicated removal and only
 * confused the admin UI, so 2026_08_19_000002 dropped it. And a real delete()
 * would violate the module's photo rule — the row is the only pointer to the
 * S3 object, so removing it while the file lives on leaves an object in the
 * bucket that can never be found again to clean up. A `deleted` row keeps
 * s3_key findable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            $table->string('image_url', 2048);
            // 640px grid asset, written beside the main file at upload time.
            $table->string('thumb_url', 2048)->nullable();
            // Nullable like natcon_photo_submissions.s3_key — room for a future
            // add-by-URL row that owns no object of ours.
            $table->string('s3_key', 255)->nullable();
            $table->string('caption', 255)->nullable();

            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('byte_size')->nullable();

            // active | hidden | deleted — one column is both the publish toggle
            // and the delete lifecycle. A string rather than an enum so a new
            // state is a code change, not DDL.
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Serves the public page exactly: this event, active only,
            // hand-ordered. ⚠️ This composite index also backs the
            // natcon_event_id FK — MySQL refuses to drop it directly (error
            // 1553), so any future change must create the replacement index in
            // its own Schema::table call BEFORE dropping this one. The sponsors
            // table hit exactly that; see 2026_08_19_000002.
            $table->index(['natcon_event_id', 'status', 'sort_order'], 'natcon_gallery_page_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_gallery_photos');
    }
};
