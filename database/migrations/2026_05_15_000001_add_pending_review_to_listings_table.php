<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the enum to include pending_review
        DB::statement(
            "ALTER TABLE `listings` MODIFY COLUMN `verification_status`
             ENUM('verified','fully_verified','flagged','pending_review') NULL"
        );

        Schema::table('listings', function (Blueprint $table) {
            $table->json('agent_edited_fields')->nullable()->after('audited_at');
            $table->json('audit_edited_fields')->nullable()->after('agent_edited_fields');
            $table->timestamp('re_submitted_at')->nullable()->after('audit_edited_fields');
        });
    }

    public function down(): void
    {
        // Reset any pending_review rows before shrinking the enum
        DB::statement(
            "UPDATE `listings` SET `verification_status` = NULL WHERE `verification_status` = 'pending_review'"
        );

        DB::statement(
            "ALTER TABLE `listings` MODIFY COLUMN `verification_status`
             ENUM('verified','fully_verified','flagged') NULL"
        );

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['agent_edited_fields', 'audit_edited_fields', 're_submitted_at']);
        });
    }
};
