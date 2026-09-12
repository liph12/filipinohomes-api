<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a convention year exist without dates or a venue.
 *
 * These three were NOT NULL because every event so far was a convention being
 * planned, where all three are known before anything else. An ARCHIVE year is
 * the other case: it is created so a past convention's gallery has somewhere to
 * live, and requiring the 2024 venue before anyone can upload the 2024 photos
 * turns a two-minute job into a research task.
 *
 * Defaulting them instead was the alternative and is worse: dateLabel() feeds
 * the public landing page, so an invented "January 1, 2024" would be printed as
 * fact. It already returns '' for a null starts_on, which is the honest answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('natcon_events', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->change();
            $table->date('ends_on')->nullable()->change();
            $table->string('venue', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created as archives have nulls here, so the columns cannot go
        // back to NOT NULL without inventing data. Filling them with a
        // placeholder would put that placeholder on the public page — the same
        // lie this migration exists to avoid. Deliberately one-way.
    }
};
