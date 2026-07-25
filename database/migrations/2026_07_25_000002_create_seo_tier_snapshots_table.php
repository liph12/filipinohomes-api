<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly snapshot of per-tier SEO inventory (row counts + URL-eligible
 * counts + freshness), written by `seo:snapshot-tiers` after the compute
 * pipeline finishes. Purpose: day-over-day deltas — "the facilities tier
 * lost 120 URLs since yesterday" is a regression alarm the live overview
 * can't produce without history (the July 2026 phantom-shard incident is
 * the motivating failure class). Recording starts with the MVP so the
 * baseline accumulates; delta display/alerts consume it in Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_tier_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('tier');                              // registry key, e.g. "facilities"
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('eligible_count')->nullable(); // URL-eligible groups (floor applied); null where N/A
            $table->timestamp('last_computed_at')->nullable();     // MAX(computed_at) of the source table at snapshot time
            $table->timestamps();

            $table->unique(['snapshot_date', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_tier_snapshots');
    }
};
