<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event gallery photos — the album behind /admin/natcon/{slug} face search.
 *
 * A separate table from natcon_photo_submissions on purpose: those are awardee
 * headshots owned by a recipient and reviewed for print; these are event
 * photographs owned by the event, indexed into a Rekognition face collection so
 * anyone can find the shots they appear in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_gallery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();
            $table->string('s3_key', 512)->unique();
            $table->string('photo_url', 1024);
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Rekognition state. faces_indexed_at NULL means "not indexed yet" —
            // the natcon:index-gallery-faces sweep picks those up, so an AWS blip
            // during upload never permanently loses a photo from search results.
            // face_ids are kept so deleting a photo can also evict its vectors
            // from the collection (DeleteFaces needs the ids back).
            $table->json('face_ids')->nullable();
            $table->unsignedSmallInteger('face_count')->nullable();
            $table->timestamp('faces_indexed_at')->nullable();
            $table->string('index_error', 512)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['natcon_event_id', 'created_at']);
            $table->index(['natcon_event_id', 'faces_indexed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_gallery_photos');
    }
};
