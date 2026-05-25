<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope dimension on blocked_users so admins can issue a single
 * site-wide ban (one row, every agent affected) while agents and
 * team leaders keep the existing per-agent semantic.
 *
 * Columns:
 *   scope         — enum('per_agent','global'); 'per_agent' default
 *                   keeps every existing row at its current meaning.
 *   agent_user_id — relaxed to nullable: global rows have no agent.
 *
 * No backfill: pre-existing rows are per_agent today, which is what
 * the default writes.
 *
 * The existing unique (agent_user_id, blocked_user_id) is intentionally
 * preserved. MySQL treats NULL values as distinct in unique indexes, so
 * multiple global rows can coexist (one per blocked_user_id) — dedup
 * for global scope is handled at the application layer via firstOrCreate
 * in BlockedUserController::store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocked_users', function (Blueprint $table) {
            $table->enum('scope', ['per_agent', 'global'])
                ->default('per_agent')
                ->after('blocked_by');

            $table->unsignedBigInteger('agent_user_id')->nullable()->change();

            $table->index(['blocked_user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('blocked_users', function (Blueprint $table) {
            $table->dropIndex(['blocked_user_id', 'scope']);
            $table->dropColumn('scope');
            $table->unsignedBigInteger('agent_user_id')->nullable(false)->change();
        });
    }
};
