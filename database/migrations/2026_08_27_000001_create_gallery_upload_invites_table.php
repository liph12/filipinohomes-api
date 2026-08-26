<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photographer upload invites: a tokenized link an admin hands to a hired
 * photographer so they can create albums and upload photos into the event
 * gallery WITHOUT an account. The token machinery mirrors natcon_recipients
 * (nonce-derived HMAC, hash stored, nonce rotation revokes) — see
 * GalleryInviteService for why the token is derived rather than random.
 *
 * upload_invite_id lands on BOTH gallery tables: it is the attribution that
 * lets the admin list count a photographer's uploads AND the ownership
 * predicate that lets a photographer delete/re-caption only what THEY
 * uploaded. nullOnDelete is a safety net only — invites are status-flipped
 * to 'revoked', never hard-deleted, precisely so attribution survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_upload_invites', function (Blueprint $table) {
            $table->id();
            // Nullable like the gallery tables: a public-scope (/albums)
            // invite is possible later without a schema change. v1 admin
            // routes only mint event-scoped ones.
            $table->foreignId('natcon_event_id')->nullable()
                ->constrained('natcon_events')->cascadeOnDelete();
            // NULL = the whole scope's gallery; set = the photographer is
            // confined to this album's subtree.
            $table->foreignId('root_album_id')->nullable()
                ->constrained('gallery_albums')->nullOnDelete();
            $table->string('label', 120);            // photographer / company name
            $table->string('status', 20)->default('active'); // active | revoked
            // Safety valve: true parks uploads as status=hidden for admin
            // review instead of going straight onto the public page.
            $table->boolean('review_required')->default(false);
            $table->char('invite_token_hash', 64)->nullable()->unique();
            $table->char('token_nonce', 32)->nullable();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            // Touched on WRITES only — a GET must stay pure (SafeLinks
            // prefetches every URL in a forwarded email).
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['natcon_event_id', 'status'], 'gallery_upload_invites_event_status_idx');
        });

        Schema::table('gallery_photos', function (Blueprint $table) {
            $table->foreignId('upload_invite_id')->nullable()->after('created_by')
                ->constrained('gallery_upload_invites')->nullOnDelete();
        });

        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->foreignId('upload_invite_id')->nullable()->after('created_by')
                ->constrained('gallery_upload_invites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // The two FKs must go BEFORE the table they point at — and
        // dropConstrainedForeignId removes the column with its own index, so
        // the MySQL-1553 shared-index trap (see the sponsors migration) never
        // arises here.
        Schema::table('gallery_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('upload_invite_id');
        });

        Schema::table('gallery_albums', function (Blueprint $table) {
            $table->dropConstrainedForeignId('upload_invite_id');
        });

        Schema::dropIfExists('gallery_upload_invites');
    }
};
