<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reactions on the public landing page's announcements.
 *
 * Anonymous by design: the NATCON landing page carries no navbar and no login,
 * so the actor is the persistent visitor id the web already keeps in
 * localStorage for acquisition tracking (`fh_vid`), not a user.
 *
 * ⚠️ The unique key is (announcement, visitor) and deliberately does NOT include
 *    the reaction. That single constraint is what produces the Facebook
 *    behaviour: one reaction per person per post, so picking a different emoji
 *    REPLACES the previous one instead of stacking a second vote. Widen it to
 *    include `reaction` and every visitor can hold all seven at once.
 *
 * Counts are aggregated on read rather than denormalised into a counter column
 * (unlike agent_reviews.helpful_count). The public read is served through a
 * caching proxy on the frontend, so the aggregate runs about twice a minute no
 * matter how busy the page is — and a counter that can drift out of sync is
 * worse than a GROUP BY that cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_announcement_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_announcement_id')
                ->constrained('natcon_announcements')
                ->cascadeOnDelete();

            // Not a user id. Clearable by the visitor, which is understood and
            // accepted — the counts are a direction, not an audited tally.
            $table->string('visitor_id', 64);

            // A key from AnnouncementReaction::KEYS, never a raw emoji: a public
            // endpoint writing arbitrary strings into a label column is an
            // unbounded set nobody can render or total meaningfully.
            $table->string('reaction', 16);

            // Abuse forensics only. Never used to identify or to dedupe — the
            // rate limiter keys on it, but shared office NAT means one IP is
            // legitimately many people.
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // One reaction per visitor per announcement. Also the lookup the
            // toggle depends on.
            $table->unique(['natcon_announcement_id', 'visitor_id'], 'natcon_reaction_actor_unq');

            // Serves the aggregate: counts per reaction for a set of posts.
            $table->index(['natcon_announcement_id', 'reaction'], 'natcon_reaction_tally_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_announcement_reactions');
    }
};
