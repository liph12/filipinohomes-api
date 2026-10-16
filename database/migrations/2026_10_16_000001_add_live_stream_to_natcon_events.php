<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The convention's live stream: a YouTube or Facebook link the admin pastes, and a switch.
 *
 * On the event row for the usual reason — it is a property of ONE convention, so next year is
 * an admin edit, not a deploy. The switch is separate from the link so the link can be prepared
 * the day before and flipped on when the stream actually starts; while it is on, the public bar
 * shows a "Live" tab with a red dot and /natcon/live embeds the player.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->string('live_url', 2048)->nullable()->after('reactions_enabled');
            $table->boolean('live_enabled')->default(false)->after('live_url');
        });
    }

    public function down(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->dropColumn(['live_url', 'live_enabled']);
        });
    }
};
