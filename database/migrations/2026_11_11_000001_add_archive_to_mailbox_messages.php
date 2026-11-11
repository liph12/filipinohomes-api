<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mailbox archive (`mailbox:archive`): website emails whose period is done are
 * copied to S3 (the full original .eml, gzipped) and removed from the mail
 * server to free its storage. The row stays — `archive_path` points at the S3
 * file the Mailbox reads instead of the mail server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('detail');
            $table->string('archive_path', 500)->nullable()->after('archived_at'); // key on the S3 disk
            $table->unsignedInteger('archive_bytes')->nullable()->after('archive_path'); // original size on the mail server
            $table->index(['account', 'archived_at'], 'mailbox_messages_archived_idx');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_messages', function (Blueprint $table) {
            $table->dropIndex('mailbox_messages_archived_idx');
            $table->dropColumn(['archived_at', 'archive_path', 'archive_bytes']);
        });
    }
};
