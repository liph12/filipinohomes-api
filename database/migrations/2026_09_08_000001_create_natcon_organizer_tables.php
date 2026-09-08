<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organizing committee chart on the public NATCON "Organizers" page.
 *
 * Modelled on the events team's own org chart: three PHASES (pre-program,
 * program proper, post-program), each a row of COMMITTEE cards, each card a
 * title, one or more heads, and a row of assistants. Two tables:
 *
 *   natcon_organizer_committees  the cards — tied to natcon_events, because the
 *                                committee is rebuilt every convention and next
 *                                year's page must not inherit this year's people
 *   natcon_organizer_members     the people on a card, with role head|assistant
 *
 * Replaces the earlier FILE-DROP convention (public/natcon/organizers-{year}/
 * with the tree encoded in dotted filenames), which nobody on the events team
 * could edit without a deploy. Everything here is edited in /natcon/admin.
 *
 * Portraits are uploaded through the shared /upload route into S3 and only the
 * URL is stored — same as sponsor logos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('natcon_organizer_committees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            // pre_program | program_proper | post_program — validated at the
            // controller; a string rather than an enum so a fourth phase is a
            // code change, not DDL.
            $table->string('phase', 20);
            $table->string('title', 191);
            // Optional caption under the people, e.g. "All staff excepting accounting".
            $table->string('note', 191)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Serves the public page exactly: this event, grouped by phase,
            // hand-ordered within the phase. Also backs the natcon_event_id FK —
            // see CLAUDE.md §7 (MySQL 1553) before ever dropping it.
            $table->index(['natcon_event_id', 'phase', 'sort_order'], 'natcon_org_committee_order_idx');
        });

        Schema::create('natcon_organizer_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained('natcon_organizer_committees')->cascadeOnDelete();

            // head | assistant. A card may carry several heads ("Jelly & Lady").
            $table->string('role', 20);
            $table->string('name', 191);
            // Nullable: some cards name a group ("Accounting staff", "All boys")
            // and get a silhouette instead of a portrait.
            $table->string('photo_url', 2048)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['committee_id', 'role', 'sort_order'], 'natcon_org_member_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_organizer_members');
        Schema::dropIfExists('natcon_organizer_committees');
    }
};
