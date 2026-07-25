<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run history for the SEO compute pipeline (seo:compute-* + friends).
 * Before this table, the only "did last night run?" signal was each derived
 * table's computed_at — which records the last SUCCESS and says nothing about
 * failures. Rows are written by BOTH trigger paths so one history serves both:
 *   - manual  → SeoCommandController creates a `queued` row, RunSeoCommand
 *               (queued job) walks it through running → success|failed|stale
 *   - schedule → routes/console.php hooks via SeoCommandRunRecorder
 * Powers the admin SEO Manage page's Jobs panel (status, duration, output).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_command_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command');                    // e.g. "seo:compute-facility-counts"
            $table->string('status');                     // queued | running | success | failed | stale
            $table->string('trigger_source');             // manual | schedule
            $table->foreignId('triggered_by')->nullable() // admin user for manual runs; null for schedule
                ->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->text('output')->nullable();           // BufferedOutput, truncated (~20KB)
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['command', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_command_runs');
    }
};
