<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A convention can run several Live Contests over the event (one per session, say), ONE open at a
 * time: a contest is open while `ended_at` is null, and the admin ends it to open the next. Every
 * registration belongs to the contest that was open when it was made, so each draw has its own pool.
 * Registrations made before contests existed keep a null contest id.
 *
 * ⚠️ Order matters on natcon_live_entries (MySQL error 1553): the (event, phone) unique was the only
 *    index backing the natcon_event_id FOREIGN KEY, so a plain index on natcon_event_id is created
 *    FIRST, then the new uniques, and only then are the old uniques dropped. Every step checks what
 *    exists, so a run that failed halfway can be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('natcon_live_contests')) {
            Schema::create('natcon_live_contests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();
                $table->string('title', 191);
                $table->timestamp('ended_at')->nullable();
                $table->timestamps();
                $table->index(['natcon_event_id', 'ended_at']);
            });
        }

        if (! $this->hasIndex('natcon_live_entries', 'natcon_live_entries_event_idx')) {
            Schema::table('natcon_live_entries', fn (Blueprint $t) => $t->index('natcon_event_id', 'natcon_live_entries_event_idx'));
        }
        if (! Schema::hasColumn('natcon_live_entries', 'natcon_live_contest_id')) {
            Schema::table('natcon_live_entries', fn (Blueprint $t) => $t->foreignId('natcon_live_contest_id')->nullable()->after('natcon_event_id')->constrained('natcon_live_contests')->nullOnDelete());
        }
        if (! $this->hasIndex('natcon_live_entries', 'natcon_live_entries_contest_phone_unique')) {
            Schema::table('natcon_live_entries', fn (Blueprint $t) => $t->unique(['natcon_live_contest_id', 'phone'], 'natcon_live_entries_contest_phone_unique'));
        }
        if (! $this->hasIndex('natcon_live_entries', 'natcon_live_entries_contest_email_unique')) {
            Schema::table('natcon_live_entries', fn (Blueprint $t) => $t->unique(['natcon_live_contest_id', 'email'], 'natcon_live_entries_contest_email_unique'));
        }
        // Uniqueness moves from the event to the CONTEST: the same agent may register for each contest.
        foreach (['natcon_live_entries_natcon_event_id_email_unique', 'natcon_live_entries_event_phone_unique'] as $old) {
            if ($this->hasIndex('natcon_live_entries', $old)) {
                Schema::table('natcon_live_entries', fn (Blueprint $t) => $t->dropUnique($old));
            }
        }
    }

    public function down(): void
    {
        Schema::table('natcon_live_entries', function (Blueprint $table) {
            $table->dropUnique('natcon_live_entries_contest_phone_unique');
            $table->dropUnique('natcon_live_entries_contest_email_unique');
            $table->dropConstrainedForeignId('natcon_live_contest_id');
            $table->unique(['natcon_event_id', 'email']);
            $table->unique(['natcon_event_id', 'phone'], 'natcon_live_entries_event_phone_unique');
            $table->dropIndex('natcon_live_entries_event_idx');
        });
        Schema::dropIfExists('natcon_live_contests');
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))->contains(fn ($i) => $i->Key_name === $name);
    }
};
