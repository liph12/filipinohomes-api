<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things the public NATCON landing page is made of.
 *
 * Everything else on that page — name, dates, venue, banner, countdown — already
 * comes from natcon_events. These are the parts that change during a campaign
 * and are written by people rather than by the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Announcements shown on the landing page.
         *
         * ⚠️ NOT the existing `announcements` table. That one is a push
         *    notification broadcast — kind / audience / recipients_count /
         *    sent_at — aimed at a mobile fleet. This is page copy. They share a
         *    word and nothing else, which is exactly why the model keeps the
         *    Natcon prefix.
         */
        Schema::create('natcon_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            $table->string('title', 191);
            $table->text('body');
            $table->string('image_url', 2048)->nullable();

            // Drafting is the normal case: someone writes an announcement before
            // it is meant to be public. Unpublished rows never leave the admin.
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_pinned')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Serves the public feed exactly: this event, published only,
            // pinned first, newest first.
            $table->index(['natcon_event_id', 'is_published', 'is_pinned', 'published_at'], 'natcon_ann_feed_idx');
        });

        /**
         * A recording of each past convention.
         *
         * ⚠️ Deliberately NOT tied to natcon_events. The conventions go back to
         *    2012 and none of those years has an event row — inventing fourteen
         *    of them, each needing dates and a venue nobody has to hand, to store
         *    a year and a URL would be the tail wagging the dog. A recap is a
         *    year and a video.
         */
        Schema::create('natcon_recaps', function (Blueprint $table) {
            $table->id();
            // Unique: one recording per convention. A second row for the same
            // year is a mistake, not a feature.
            $table->unsignedSmallInteger('year')->unique();
            $table->string('title', 191);
            $table->string('video_url', 2048);
            $table->string('thumbnail_url', 2048)->nullable();
            $table->boolean('is_published')->default(true);
            // Year descending is the obvious order, but the sketch shows a
            // hand-arranged list, so let it be overridden.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'sort_order', 'year'], 'natcon_recap_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_recaps');
        Schema::dropIfExists('natcon_announcements');
    }
};
