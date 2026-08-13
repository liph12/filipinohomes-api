<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upload history, not a single column on natcon_recipients. Re-uploads must be
     * recoverable (someone sends the wrong photo, uploads again, then wants the
     * first one back) and the events-team QC pass needs somewhere to live without
     * a later schema change.
     *
     * natcon_recipients.current_photo_url is the denormalized "active" pointer;
     * this table is the history. No FK back from recipients — that would be a
     * circular constraint.
     */
    public function up(): void
    {
        Schema::create('natcon_photo_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_recipient_id')->constrained('natcon_recipients')->cascadeOnDelete();
            // Denormalized so event-scoped export queries don't need the join.
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            $table->string('photo_url', 2048);          // absolute S3 URL
            $table->string('s3_key', 512);
            $table->string('original_filename', 255)->nullable();
            // image/jpeg — we deliberately store JPEG rather than WebP because these
            // files get handed to an events/print workflow where WebP support is poor.
            $table->string('mime_type', 64)->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            $table->string('status', 16)->default('active');          // active | superseded | deleted
            $table->string('review_status', 16)->default('pending');  // pending | approved | rejected
            $table->string('review_note', 512)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('uploaded_ip', 45)->nullable();
            $table->string('uploaded_user_agent', 255)->nullable();

            $table->timestamps();

            $table->index(['natcon_recipient_id', 'status']);
            $table->index(['natcon_event_id', 'status']);
            $table->index(['natcon_event_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_photo_submissions');
    }
};
