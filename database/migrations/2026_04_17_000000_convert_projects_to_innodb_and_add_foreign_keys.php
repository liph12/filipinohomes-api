<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE projects ENGINE=InnoDB');

        DB::table('properties')
            ->whereNotNull('project_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('projects')
                    ->whereColumn('projects.id', 'properties.project_id');
            })
            ->update(['project_id' => null]);

        DB::statement('ALTER TABLE properties MODIFY project_id INT NULL');

        Schema::table('properties', function (Blueprint $table) {
            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        DB::statement('ALTER TABLE properties MODIFY project_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE projects ENGINE=MyISAM');
    }
};
