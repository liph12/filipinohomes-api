<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->integer('project_id')->nullable()->after('is_project');
            $table->index('project_id');
            $table->index(['is_project', 'project_id']);
        });

        DB::statement("
            UPDATE properties p
            INNER JOIN (
                SELECT MIN(id) AS id, LOWER(TRIM(name)) AS normalized_name
                FROM projects
                WHERE TRIM(name) <> ''
                GROUP BY LOWER(TRIM(name))
            ) pr ON pr.normalized_name = LOWER(TRIM(p.name))
            SET p.project_id = pr.id
            WHERE p.is_project = 1
                AND p.project_id IS NULL
                AND TRIM(p.name) <> ''
        ");
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropIndex(['is_project', 'project_id']);
            $table->dropColumn('project_id');
        });
    }
};
