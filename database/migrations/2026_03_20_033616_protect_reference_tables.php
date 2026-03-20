<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['property_types', 'property_subtypes', 'amenities', 'furnishings'];

        foreach ($tables as $table) {
            DB::unprepared("
                CREATE TRIGGER protect_{$table}_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' 
                    SET MESSAGE_TEXT = 'Action not allowed on {$table}';
                END
            ");

            DB::unprepared("
                CREATE TRIGGER protect_{$table}_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' 
                    SET MESSAGE_TEXT = 'Action not allowed on {$table}';
                END
            ");
        }
    }

    public function down(): void
    {
        $tables = ['property_types', 'property_subtypes', 'amenities', 'furnishings'];

        foreach ($tables as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS protect_{$table}_delete");
            DB::unprepared("DROP TRIGGER IF EXISTS protect_{$table}_update");
        }
    }
};