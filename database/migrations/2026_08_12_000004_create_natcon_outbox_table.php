<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every NATCON message that has been claimed for sending.
     *
     * The `audits` table already logs each MessageSent (see AuditMailService),
     * but it is append-only forensics and cannot answer "who has NOT been
     * emailed yet?". This is that queryable state, and it is also the
     * double-send guard.
     *
     * ─── There is deliberately no separate batches table ────────────────────
     * A send batch is just the set of rows sharing a batch_id; progress is
     * GROUP BY batch_id and needs no denormalized counters. An earlier design
     * kept a natcon_send_batches row with sent/failed/skipped columns, and the
     * drain overwrote its queue-time `skipped` value within 60 seconds of every
     * send — deriving from one place makes that unrepresentable.
     *
     * The per-batch forensic snapshot (which filters the admin targeted) is
     * written as one audit row instead, where a human will actually find it.
     *
     * ⚠️ Never prune this table. The replay guard on POST /send-invites is
     *    `where('batch_id', ?)->exists()`, so deleting old rows would let a
     *    replayed request queue a second blast.
     */
    public function up(): void
    {
        Schema::create('natcon_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_recipient_id')->constrained('natcon_recipients')->cascadeOnDelete();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            $table->string('kind', 16);                  // invite | reminder | resend
            $table->date('send_date');                   // calendar date in Asia/Manila
            $table->unsignedTinyInteger('reminder_index')->nullable();  // 1|2|3 — copy only, not identity
            $table->char('batch_id', 36)->nullable();

            // Nullable on purpose: only one of claimSend()'s three callers has an
            // authenticated user. The public resend-link endpoint and the
            // reminder cron both queue rows with nobody behind them.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('queued');  // queued | sent | failed | cancelled
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('subject', 255)->nullable();
            $table->string('error', 512)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            // ★ THE idempotency guarantee: at most one invite and one reminder per
            //   recipient per calendar day, enforced by MySQL rather than by code
            //   discipline. A double-clicking admin, a cron double-fire, the
            //   frontend axios interceptor's transparent 401 replay, and a drain
            //   retry all collapse to exactly one email.
            //
            //   Keyed on send_date rather than reminder_index on purpose: moving
            //   the deadline changes which offsets fire, and we must never
            //   re-send a day that already went out.
            $table->unique(['natcon_recipient_id', 'kind', 'send_date'], 'natcon_outbox_recipient_kind_date_unique');

            $table->index(['status', 'id']);             // the drain command's hot path
            // Batch progress. Composite so the endpoint an open admin dialog
            // polls during a live send is index-only.
            $table->index(['batch_id', 'status'], 'natcon_outbox_batch_status_idx');
            $table->index(['natcon_event_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_outbox');
    }
};
