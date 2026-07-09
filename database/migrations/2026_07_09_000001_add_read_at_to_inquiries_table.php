<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Read-tracking for the admin Contact Inbox: read_at is null until an admin
// opens the submission, so the list can flag unread rows and the sidebar can
// show an unread badge. Indexed for the fast COUNT(*) WHERE read_at IS NULL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('city');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex(['read_at']);
            $table->dropColumn('read_at');
        });
    }
};
