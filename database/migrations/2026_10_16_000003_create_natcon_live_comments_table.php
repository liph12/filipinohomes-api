<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every live-chat message of the convention's YouTube stream, as collected while it is on air — the
 * raw material for "everyone who commented is in the raffle". One row per message; the commenter is
 * denormalised onto it (YouTube's public display name, channel id and avatar — YouTube exposes no
 * email or real name to anyone), and the admin groups by channel_id for the commenter list.
 *
 * Collected by natcon:collect-live-chat (scheduler, every minute while live_enabled is on) through
 * the Data API's liveChatMessages feed, which is readable only WHILE the stream is live — hence a
 * table rather than a read at raffle time. Any public channel's stream, not only our own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_live_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();
            $table->string('video_id', 32);
            $table->string('message_id', 191)->unique();
            $table->string('channel_id', 64);
            $table->string('display_name', 191);
            $table->string('avatar_url', 2048)->nullable();
            $table->text('text');
            $table->timestamp('published_at');
            $table->timestamps();
            $table->index(['natcon_event_id', 'video_id']);
            $table->index(['natcon_event_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_live_comments');
    }
};
