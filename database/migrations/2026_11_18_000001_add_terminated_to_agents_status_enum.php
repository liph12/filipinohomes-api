<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's schema builder can't alter enum columns, so raw SQL it is.
        // `terminated` goes last: appending a member only changes the column's
        // metadata, while inserting one mid-list would rebuild the table.
        DB::statement("ALTER TABLE agents MODIFY status ENUM('active', 'inactive', 'resigned', 'deactivated', 'terminated') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        // Move rows off the value being removed before shrinking the enum.
        DB::table('agents')->where('status', 'terminated')->update(['status' => 'resigned']);
        DB::statement("ALTER TABLE agents MODIFY status ENUM('active', 'inactive', 'resigned', 'deactivated') NOT NULL DEFAULT 'active'");
    }
};
