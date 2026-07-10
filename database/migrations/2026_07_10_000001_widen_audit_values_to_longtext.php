<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen audits.old_values / new_values from TEXT (~64KB) to LONGTEXT so the
 * mailer audit rows can store rendered email bodies (full HTML/text of what
 * was sent) without overflowing the column. Pure widening — safe for all
 * existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->longText('old_values')->nullable()->change();
            $table->longText('new_values')->nullable()->change();
        });
    }

    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->text('old_values')->nullable()->change();
            $table->text('new_values')->nullable()->change();
        });
    }
};
