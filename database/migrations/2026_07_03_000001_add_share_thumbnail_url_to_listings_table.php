<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent-chosen share thumbnail: an S3 URL of a flyer capture the owning agent
 * promoted from the Flyer modal. When set, the listing page emits it as the
 * primary og:image (the generated /og/listing card becomes the fallback);
 * null = default card. Set/reset via PATCH /listings/{listing}/share-thumbnail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('share_thumbnail_url', 512)->nullable()->after('featured_photo');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('share_thumbnail_url');
        });
    }
};
