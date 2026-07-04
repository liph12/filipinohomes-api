<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-listing customization of the DEFAULT generated share card (/og/listing):
 * { photo, theme, flip, hide[], agent }. Options are re-applied to live listing
 * data on every scrape (generateListingMetadata), so the customized card stays
 * price/title-fresh. Precedence: share_thumbnail_url (flyer capture) wins over
 * these options; null = plain default card. Set/reset via the same
 * PATCH /listings/{listing}/share-thumbnail endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->json('og_card_options')->nullable()->after('share_thumbnail_url');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('og_card_options');
        });
    }
};
