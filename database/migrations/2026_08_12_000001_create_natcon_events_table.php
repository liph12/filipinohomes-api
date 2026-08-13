<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per convention year. NATCON 2026, 2027, 2028 … all live here and
     * every other natcon_* table hangs off natcon_event_id, so past years stay
     * queryable as an archive while the current one runs.
     *
     * A table rather than config constants so the photo deadline, the reminder
     * cadence and the per-year branding are DATA the admin can edit — moving a
     * deadline must not require a code deploy during the week nobody has time
     * for one.
     */
    public function up(): void
    {
        Schema::create('natcon_events', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();          // 'natcon-2026'
            // Drives the public /natcon/{year} route, so it's an explicit column
            // rather than derived from starts_on — event dates move, URLs must not.
            $table->unsignedSmallInteger('year')->unique();
            $table->string('name');                        // 'National Real Estate Convention 2026'
            $table->string('short_name', 64);              // 'NATCON 2026' — used in email subjects
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('venue');
            $table->string('hashtag', 64)->nullable();     // '#LRNATCON2026'

            // Stored UTC because config('app.timezone') is UTC. The business
            // deadline "the 24th" means 2026-08-24 23:59:59 Asia/Manila, which is
            // 2026-08-24 15:59:59 UTC. Always compare through `timezone` below.
            $table->timestamp('photo_deadline_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');

            // Where the emailed CTA lands. Kept here so the frontend host can
            // change (staging/preview) without touching the mailer.
            $table->string('update_profile_url');

            // ── Per-year branding ─────────────────────────────────────────────
            // Frontend responsive-image base, e.g. '/images/natcon-2026/natcon2026'.
            // A new year drops a new folder and edits this — no code change.
            $table->string('banner_base', 255)->nullable();
            // Absolute URL for the email banner. Must be stable and public: Gmail
            // proxies and caches remote images, so a path that moves breaks every
            // message already delivered.
            $table->string('email_banner_url', 2048)->nullable();
            // The confirmation shown after Retain. A column, not a PHP literal, so
            // marketing can genuinely reword it without any deploy.
            $table->string('thank_you_message', 512)->nullable();

            // Days-before-deadline on which reminders fire. [4,3,2] against an
            // Aug 24 deadline == Aug 20 / 21 / 22. Shifting the deadline shifts
            // the reminders automatically.
            $table->json('reminder_offsets')->nullable();

            $table->boolean('is_active')->default(true);

            // Denormalized snapshots (cf. announcements.recipients_count).
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('invited_count')->default(0);
            $table->unsignedInteger('responded_count')->default(0);
            $table->unsignedInteger('photo_uploaded_count')->default(0);
            $table->unsignedInteger('form_submitted_count')->default(0);

            $table->timestamps();

            $table->index('is_active');
            // NatconEvent::active() resolves newest-first on this.
            $table->index(['is_active', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_events');
    }
};
