<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Mailbox index — a light copy of the website emails in the shared
 * mailbox (info@), kept in sync by `mailbox:sync` every minute so the
 * dashboard reads the database instead of logging in to the mail server on
 * every click. Headers + flags only; bodies are cached after first open
 * (`detail`). The mail server stays the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_messages', function (Blueprint $table) {
            $table->id();
            // Which mailbox account (address + server) — so switching MAILBOX_* never mixes data.
            $table->string('account', 32);
            $table->string('folder', 16);          // inbox, sent, drafts, spam, trash, archive
            $table->string('folder_path', 191);    // server path (INBOX, INBOX.Sent, …)
            $table->unsignedBigInteger('uidvalidity');
            $table->unsignedBigInteger('uid');
            $table->string('message_id', 191)->nullable(); // copies of one email share it
            $table->boolean('is_primary')->default(true);  // false = a duplicate copy (hidden)
            $table->string('category', 32)->nullable();     // listing, contact_us, activity_report, …
            $table->string('from_name', 191)->default('');
            $table->string('from_email', 191)->default('');
            $table->json('to')->nullable();
            $table->string('subject', 500)->default('');
            $table->timestamp('sent_at')->nullable();       // the email's Date header
            $table->boolean('seen')->default(false);
            $table->boolean('flagged')->default(false);
            $table->boolean('answered')->default(false);
            // Set when the dashboard changed flags; the sync won't overwrite them for a
            // moment, so a click isn't undone before the mail server catches up.
            $table->timestamp('local_change_at')->nullable();
            $table->longText('detail')->nullable();         // cached opened view (html, cc, attachments…)
            $table->timestamps();

            $table->unique(['account', 'folder_path', 'uidvalidity', 'uid'], 'mailbox_messages_uid_unique');
            $table->index(['account', 'folder', 'is_primary', 'category', 'sent_at'], 'mailbox_messages_list_idx');
            $table->index(['account', 'folder', 'message_id'], 'mailbox_messages_copies_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_messages');
    }
};
