<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE messages MODIFY COLUMN status ENUM('active', 'updated', 'deleted', 'unsent') DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE messages MODIFY COLUMN status ENUM('active', 'updated', 'deleted') DEFAULT 'active'");
    }
};
