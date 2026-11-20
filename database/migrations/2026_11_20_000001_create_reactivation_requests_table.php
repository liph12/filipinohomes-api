<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deactivated agent (45 days without logging in) asking an admin to turn
     * their account back on. One row per request: the agent explains the gap and
     * promises to log in regularly, an admin accepts or declines it.
     *
     * `status` is a short string rather than a MySQL enum so a new state never
     * needs raw ALTER TABLE SQL: pending | approved | declined.
     *
     * A declined agent may file a new request later, so there is deliberately no
     * unique key on agent_id — "one pending at a time" is enforced in the
     * controller.
     */
    public function up(): void
    {
        Schema::create('reactivation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 10)->default('pending'); // pending | approved | declined
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactivation_requests');
    }
};
