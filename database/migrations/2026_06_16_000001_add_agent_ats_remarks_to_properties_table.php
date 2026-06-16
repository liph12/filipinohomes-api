<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agent's own note about the ATS attachment — separate from the
     * admin/auditor `ats_remarks`. Used on listing EDIT: when an agent updates
     * a listing without ATS attachments, they explain why here. Optional by
     * default; required (enforced in UpdateListingRequest) only when the edit
     * leaves the ATS attachments empty.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'agent_ats_remarks')) {
                $table->text('agent_ats_remarks')->nullable()->after('ats_remarks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'agent_ats_remarks')) {
                $table->dropColumn('agent_ats_remarks');
            }
        });
    }
};
