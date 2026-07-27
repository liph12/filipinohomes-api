<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-pinned role flag. When true, the user's role_id was set deliberately
 * by an admin in System Users and every LR-driven role sync (dev login's
 * existing-user re-sync; any future sync path) must leave it alone — e.g. an
 * LR-admin account demoted to agent on FH must stay agent across her Google
 * sign-ins. Unchecking the box in System Users clears the flag so the next
 * login may re-derive the role from LR again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('role_locked')->default(false)->after('role_id');
        });
    }


    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role_locked');
        });
    }
};
