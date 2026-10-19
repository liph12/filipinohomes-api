<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The convention's earlier live streams, kept as replays. Written by the admin's save: when the
 * live switch goes off, or the link changes while on, the stream that was playing is recorded
 * here (YouTube and Facebook keep a finished live as a video at the same link), and /natcon/live
 * shows them under the player so a visitor who opens the page after a session still sees it.
 * `live_started_at` on the event is when the current stream went on, so a replay knows its date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('natcon_events', 'live_started_at')) {
            Schema::table('natcon_events', fn (Blueprint $table) => $table->timestamp('live_started_at')->nullable()->after('live_enabled'));
        }
        if (! Schema::hasTable('natcon_live_replays')) {
            Schema::create('natcon_live_replays', function (Blueprint $table) {
                $table->id();
                $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();
                $table->string('url', 2048);
                $table->string('url_hash', 64);
                $table->string('title', 191)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at');
                $table->timestamps();
                $table->unique(['natcon_event_id', 'url_hash'], 'natcon_live_replays_event_url_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_live_replays');
        if (Schema::hasColumn('natcon_events', 'live_started_at')) {
            Schema::table('natcon_events', fn (Blueprint $table) => $table->dropColumn('live_started_at'));
        }
    }
};
