<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent-side chat replies that say a listing is already sold, rented or
     * taken ("sold na po", "nahalin na", "no longer available") — one row per
     * matching message, written by `reports:scan-sold-claims`.
     *
     * Precomputed because finding them means reading `messages.body`, and
     * nothing that scans message text may run inside a page request. The
     * Executive Report reads this table and re-checks the listing's CURRENT
     * status on every read, so a row stops counting the moment the listing is
     * marked sold / rented / leased — nothing here has to be cleaned up for
     * that.
     *
     * The snippet is kept because the message itself can lose its text later
     * (unsend / delete blank `messages.body`); the scanner's re-check pass
     * removes those rows rather than show text the agent took back.
     *
     * No foreign keys, like the rest of the reporting tables: a claim row must
     * never block deleting a message or a listing.
     */
    public function up(): void
    {
        Schema::create('listing_sold_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('message_id')->unique();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('listing_id')->index();
            $table->unsignedBigInteger('speaker_user_id')->index();
            $table->string('speaker_role', 16)->nullable();
            $table->unsignedBigInteger('client_user_id')->nullable();
            // strong | medium | weak — see SoldClaimMatcher. Only strong and
            // medium are ever counted; weak is kept for tuning the matcher.
            $table->string('tier', 8);
            $table->string('phrase', 64);
            $table->string('snippet', 300);
            $table->timestamp('said_at')->index();
            $table->unsignedSmallInteger('matcher_version');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['tier', 'said_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_sold_claims');
    }
};
