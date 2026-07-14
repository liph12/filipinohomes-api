<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated YouTube listing videos. youtube:process-uploads generates a
 * slideshow MP4 per eligible public listing and uploads it to the brand
 * channel; the resulting video id is stored here so the frontend can embed
 * the player + emit VideoObject schema, and so a listing is never uploaded
 * twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // YouTube's 11-char video id. Unique — one video per listing.
            $table->string('youtube_video_id', 32)->nullable()->unique()->after('og_card_options');
            // pending | uploaded | failed — 'failed' rows are skipped by the
            // picker so a broken listing can't wedge the daily quota.
            $table->string('youtube_video_status', 16)->nullable()->after('youtube_video_id');
            $table->timestamp('youtube_video_uploaded_at')->nullable()->after('youtube_video_status');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn([
                'youtube_video_id',
                'youtube_video_status',
                'youtube_video_uploaded_at',
            ]);
        });
    }
};
