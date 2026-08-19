<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sponsor logos shown on the public NATCON landing page, in three tiers:
 * major sponsors, minor sponsors and star benefactors.
 *
 * Tied to natcon_events (unlike recaps) — sponsorship is per convention, and
 * next year's page must not inherit this year's sponsors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            // major | minor | star — validated at the controller; a string
            // rather than an enum so a future tier is a code change, not DDL.
            $table->string('tier', 20);
            $table->string('name', 191)->nullable();
            $table->string('image_url', 2048);

            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Serves the public page exactly: this event, published only,
            // grouped by tier, hand-ordered within the tier.
            $table->index(['natcon_event_id', 'tier', 'is_published', 'sort_order'], 'natcon_sponsor_page_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_sponsors');
    }
};
