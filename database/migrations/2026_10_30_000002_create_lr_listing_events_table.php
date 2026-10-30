<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Leuterio Realty website did with our listings, through the /api/lr endpoints (X-LR-Token):
 *   - lr_listing_events: every lookup of a listing code and every status call (history);
 *   - listings.lr_used_at / lr_status_changed_at: the first LR lookup and the last real LR status
 *     change, so the admin listing cards can show "Used in LR" / "Status updated via LR" cheaply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lr_listing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            // lookup = LR searched the code; status = LR changed it; confirmed = LR sent the current status.
            $table->string('action', 16);
            $table->string('email');
            $table->string('matched_by', 16); // fh_email | lr_email
            $table->string('old_status', 16)->nullable();
            $table->string('new_status', 16)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['listing_id', 'created_at']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('lr_used_at')->nullable();
            $table->timestamp('lr_status_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['lr_used_at', 'lr_status_changed_at']);
        });
        Schema::dropIfExists('lr_listing_events');
    }
};
