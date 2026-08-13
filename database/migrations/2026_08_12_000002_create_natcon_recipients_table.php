<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per awardee we intend to contact, carrying a local snapshot of the
     * Leuterio Realty awardee record plus our own invite/response state.
     *
     * The LR snapshot is deliberately denormalized onto this row: LR rate-limits
     * to 60 req/min from a single IP, so a send batch must never re-query them.
     * `lr_lookup_status` is orthogonal to `status` — an awardee LR has never heard
     * of can still legitimately be invited using the no-photo email variant.
     */
    public function up(): void
    {
        Schema::create('natcon_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('natcon_event_id')->constrained('natcon_events')->cascadeOnDelete();

            // Normalized lower+trim by the model mutator, not just the controller —
            // the unique index alone won't catch John@x.com vs john@x.com arriving
            // from two different code paths.
            $table->string('email', 191);

            // ── Leuterio Realty snapshot (their camelCase -> our snake_case) ──
            $table->unsignedBigInteger('lr_awardee_id')->nullable();   // LR "id"
            $table->string('reg_id', 64)->nullable();                  // "regId"
            $table->string('first_name', 191)->nullable();
            $table->string('last_name', 191)->nullable();
            $table->string('phone', 32)->nullable();                   // NEVER exposed publicly
            $table->string('team', 191)->nullable();
            $table->string('owner_name', 191)->nullable();             // "owner"
            $table->string('seat_number', 32)->nullable();             // null on LR today
            $table->string('lr_polo_shirt_size', 16)->nullable();      // null on LR today
            $table->boolean('lr_approved')->nullable();
            $table->json('lr_photos')->nullable();                     // string[]
            $table->string('lr_primary_photo', 2048)->nullable();      // "photo"
            $table->string('lr_qr_code', 512)->nullable();             // relative key, not a URL
            $table->json('lr_payload')->nullable();                    // full raw object, forensic
            $table->timestamp('lr_fetched_at')->nullable();

            // pending | found | not_found | error
            // `error` MUST be retried; `not_found` must NOT be. Collapsing the two
            // (as a bare ?array return would) means either giving up on real misses
            // or hammering LR forever.
            $table->string('lr_lookup_status', 16)->default('pending');
            $table->string('lr_last_error', 512)->nullable();

            // ── Provenance — the swappable import seam ──
            $table->string('source', 24)->default('manual');           // manual | paste | csv | lr_bulk
            $table->char('imported_batch_id', 36)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // ── Invite state machine ──
            // pending | queued | invited | reminded | responded_retain
            // | responded_change | photo_uploaded | completed | failed | excluded
            $table->string('status', 24)->default('pending');

            // HMAC-SHA256 of the raw 64-char token. We store the hash and emit the
            // raw (Sanctum's model) so DB backups and read replicas never yield a
            // working link.
            $table->char('invite_token_hash', 64)->nullable()->unique();
            // Rotation lever. The token is HMAC(id:event_id:nonce, secret) —
            // derived rather than random so a reminder reproduces the same link
            // the invite carried and every email we've sent keeps working. It
            // can't derive from token_issued_at: that's a second-granular
            // timestamp, so re-minting twice inside one second would produce a
            // byte-identical token and "issue a new link" would revoke nothing.
            $table->char('token_nonce', 32)->nullable();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->unsignedSmallInteger('open_count')->default(0);

            // ── Response ──
            $table->string('response', 16)->nullable();                // retain | change
            $table->timestamp('responded_at')->nullable();
            $table->string('retained_photo_url', 2048)->nullable();    // which LR photo they kept
            $table->string('current_photo_url', 2048)->nullable();     // denormalized active upload
            $table->timestamp('photo_uploaded_at')->nullable();
            $table->timestamp('form_submitted_at')->nullable();        // ships now, written in Phase 2

            $table->unsignedTinyInteger('send_failures')->default(0);
            $table->string('last_error', 512)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['natcon_event_id', 'email']);
            $table->index(['natcon_event_id', 'status']);
            $table->index(['natcon_event_id', 'response']);
            $table->index(['natcon_event_id', 'lr_lookup_status']);
            $table->index('invited_at');
            $table->index('imported_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('natcon_recipients');
    }
};
