<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What Leuterio Realty says about each agent's employment, recorded by
        // agents:sync-lr-status. Named "employment" so nobody confuses it with
        // lr_state, which is LR's province. None of it changes agents.status —
        // only agents:apply-lr-status does that, after a review.
        //
        // Appended without `after()`: a column added at the end of a MySQL 8
        // table is an instant change, and this table is shared with other LR apps.
        Schema::table('agents', function (Blueprint $table) {
            // found | not_found | error — NULL until the first lookup.
            $table->string('lr_employment_lookup', 16)->nullable();
            // LR's `status`, lowercased. '' = LR answered but has none; NULL = never found.
            $table->string('lr_employment_status', 32)->nullable();
            $table->timestamp('lr_employment_checked_at')->nullable();
            // When LR's value last CHANGED from one we had recorded. NULL on the
            // first observation, so an admin's earlier decision still counts.
            $table->timestamp('lr_employment_changed_at')->nullable();

            $table->index('lr_employment_checked_at', 'agents_lr_employment_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex('agents_lr_employment_checked_idx');
            $table->dropColumn([
                'lr_employment_lookup',
                'lr_employment_status',
                'lr_employment_checked_at',
                'lr_employment_changed_at',
            ]);
        });
    }
};
