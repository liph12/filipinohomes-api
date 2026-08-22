<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-admin mute for the admin notification email fan-outs (listing-
     * inquiry pending-review, Get In Touch, Contact Us). Set from the
     * System Users list; only meaningful for role_id=1 users but lives on
     * users so it survives role changes. Complements the global
     * `admin_emails_muted` Setting kill switch.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('admin_emails_muted')->default(false)->after('role_locked');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_emails_muted');
        });
    }
};
