<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's schema builder can't alter enum columns, so raw SQL it is.
        DB::statement("ALTER TABLE agents MODIFY status ENUM('active', 'inactive', 'resigned', 'deactivated') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        // Move rows off the value being removed before shrinking the enum.
        DB::table('agents')->where('status', 'deactivated')->update(['status' => 'inactive']);
        DB::statement("ALTER TABLE agents MODIFY status ENUM('active', 'inactive', 'resigned') NOT NULL DEFAULT 'active'");
    }
};
