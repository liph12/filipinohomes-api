<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the FH "secretary" role (id 5). Production does not run seeders, so the
     * role row is inserted via this migration. Idempotent upsert on id — matches the
     * RoleSeeder pattern, safe to re-run.
     */
    public function up(): void
    {
        DB::table('roles')->upsert(
            [['id' => 5, 'name' => 'secretary']],
            ['id'],
            ['name']
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('id', 5)->where('name', 'secretary')->delete();
    }
};
