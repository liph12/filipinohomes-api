<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-year display settings for the landing page's sponsor block — tile
 * width/height, logos per row, gaps and tile background, per tier. Lives on
 * natcon_events like every per-year knob (see components/natcon/CLAUDE.md);
 * null means "the design's defaults".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->json('sponsor_display')->nullable()->after('email_banner_url');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->dropColumn('sponsor_display');
        });
    }
};
