<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listing views per Philippine day. listings.clicks is a running total with no history, so the admin
 * dashboard could show "how many views" but never "are views going up". Each counted view (one per
 * device per listing per day, the same rule as clicks) also bumps that day's row here. Starts empty:
 * the trend builds from the day this is deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('listing_view_days')) {
            Schema::create('listing_view_days', function (Blueprint $table) {
                $table->date('date');
                $table->unsignedBigInteger('listing_id');
                $table->unsignedInteger('views')->default(0);
                $table->primary(['date', 'listing_id']);
                $table->index('listing_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_view_days');
    }
};
