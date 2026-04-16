<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    public function up(): void
    {
        DB::table('projects')
            ->whereNotNull('created_by')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'projects.created_by');
            })
            ->update(['created_by' => null]);

        DB::table('projects')
            ->whereNotNull('updated_by')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'projects.updated_by');
            })
            ->update(['updated_by' => null]);

        DB::table('projects')
            ->whereNotNull('deleted_by')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'projects.deleted_by');
            })
            ->update(['deleted_by' => null]);

        Schema::table('projects', function (Blueprint $table) {
            if (!$this->foreignKeyExists('projects', 'projects_created_by_foreign')) {
                $table->foreign('created_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (!$this->foreignKeyExists('projects', 'projects_updated_by_foreign')) {
                $table->foreign('updated_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (!$this->foreignKeyExists('projects', 'projects_deleted_by_foreign')) {
                $table->foreign('deleted_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if ($this->foreignKeyExists('projects', 'projects_created_by_foreign')) {
                $table->dropForeign('projects_created_by_foreign');
            }

            if ($this->foreignKeyExists('projects', 'projects_updated_by_foreign')) {
                $table->dropForeign('projects_updated_by_foreign');
            }

            if ($this->foreignKeyExists('projects', 'projects_deleted_by_foreign')) {
                $table->dropForeign('projects_deleted_by_foreign');
            }
        });
    }
};
