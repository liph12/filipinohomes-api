<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agent-website customization, all JSON and nullable (null = defaults):
// - theme: {"gold","brand","title","description"} hex palette + hero text
//   colors; every surface derives from the pair.
// - banner_settings: {"pos_x":0-100, "pos_y":0-100, "overlay":0-100} — the
//   banner's focal point and the headline-side dark overlay strength.
// - featured_listings: listing ids in display order — the hand-arranged
//   leaders of the Featured Listings section (the rest follow newest first).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->json('theme')->nullable()->after('heading');
            $table->json('banner_settings')->nullable()->after('theme');
            $table->json('featured_listings')->nullable()->after('banner_settings');
        });
    }

    public function down(): void
    {
        Schema::table('page_builder', function (Blueprint $table) {
            $table->dropColumn(['theme', 'banner_settings', 'featured_listings']);
        });
    }
};
