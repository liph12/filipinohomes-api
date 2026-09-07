<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company events managed from the dashboard (open houses, caravans, tree
 * plantings — anything with a title, a time, a place and pictures).
 *
 * Named company_events, NOT events: NatconEvent already owns the word one
 * namespace away, and Event/NatconEvent side by side is exactly the
 * Announcement/NatconAnnouncement wrong-import trap the NATCON CLAUDE.md
 * warns about.
 *
 * Times follow the house rule: stored UTC, edited as a wall clock in the
 * event's own timezone (see natcon photo_deadline_at — the walked-deadline
 * bug, twice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_events', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('place', 255);
            // active | deleted — a string, not an enum; no SoftDeletes, the
            // row keeps pointing at its S3 photos (gallery convention).
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at'], 'company_events_status_starts_idx');
        });

        Schema::create('company_event_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_event_id')->constrained('company_events')->cascadeOnDelete();
            // URLs computed once at upload time and stored absolute, plus the
            // key — the row is the only pointer to the S3 object.
            $table->string('image_url', 2048);
            $table->string('thumb_url', 2048);
            $table->string('s3_key', 1024);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['company_event_id', 'status', 'sort_order'],
                'company_event_photos_event_status_sort_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_event_photos');
        Schema::dropIfExists('company_events');
    }
};
