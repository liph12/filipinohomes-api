<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the `auditable_type` + `auditable_id` columns nullable.
     *
     * The original migration used `$table->morphs('auditable')` which
     * generates NOT NULL columns. That works for the LogsActivity
     * trait (a model mutation always has the model itself as the
     * auditable), but breaks the custom writers — AuditAuthService
     * (login events have no model subject) and AuditMailService
     * (system-level emails like OTP have no model subject).
     *
     * The failure mode pre-migration: AuditMailService::recordSent
     * tries to insert a row with auditable_type=null, MySQL rejects
     * it with SQLSTATE[23000], the inner try/catch swallows the
     * exception, the warning lands in laravel.log, and zero
     * mailer audit rows appear in /admin/activity-logs.
     */
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->string('auditable_type')->nullable()->change();
            $table->unsignedBigInteger('auditable_id')->nullable()->change();
        });
    }

    /**
     * No reverse — if mailer/auth rows with null auditable have
     * been written by the time someone tries to roll back, the
     * NOT NULL re-application would fail mid-migration. Treat this
     * as a forward-only schema relaxation; reverting would require
     * a manual cleanup of the null rows first.
     */
    public function down(): void
    {
        //
    }
};
