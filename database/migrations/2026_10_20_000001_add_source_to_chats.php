<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an agent inquiry ("Message me", chats.type = agent) was sent from: the agent's profile page on
 * Filipinohomes (agent_profile) or their own website-builder site (agent_page). It used to drive only
 * the notification email's wording and was never stored, so Agent Insights could not tell the two apart.
 * Older rows stay NULL ("not recorded").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'source')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->string('source', 32)->nullable()->after('type_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chats', 'source')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
