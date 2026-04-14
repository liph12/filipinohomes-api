<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend enum to include 'rejected'
        DB::statement("ALTER TABLE properties MODIFY ats_status ENUM('approve','pending','expired','rejected') NULL");
    }

    public function down(): void
    {
        // Revert to original enum without 'rejected'
        DB::statement("ALTER TABLE properties MODIFY ats_status ENUM('approve','pending','expired') NULL");
    }
};
