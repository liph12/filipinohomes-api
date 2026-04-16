<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')->where('prov_id', 0)->update(['prov_id' => null]);
        DB::table('projects')->where('city_id', 0)->update(['city_id' => null]);
        DB::table('projects')->where('brgy_id', 0)->update(['brgy_id' => null]);

        DB::statement('ALTER TABLE projects MODIFY prov_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE projects MODIFY city_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE projects MODIFY brgy_id BIGINT UNSIGNED NULL');

        DB::table('projects')
            ->whereNotNull('prov_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('provinces')
                    ->whereColumn('provinces.id', 'projects.prov_id');
            })
            ->update(['prov_id' => null]);

        DB::table('projects')
            ->whereNotNull('city_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('cities')
                    ->whereColumn('cities.id', 'projects.city_id');
            })
            ->update(['city_id' => null]);

        DB::table('projects')
            ->whereNotNull('brgy_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('barangays')
                    ->whereColumn('barangays.id', 'projects.brgy_id');
            })
            ->update(['brgy_id' => null]);

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('prov_id')
                ->references('id')
                ->on('provinces')
                ->nullOnDelete();

            $table->foreign('city_id')
                ->references('id')
                ->on('cities')
                ->nullOnDelete();

            $table->foreign('brgy_id')
                ->references('id')
                ->on('barangays')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['prov_id']);
            $table->dropForeign(['city_id']);
            $table->dropForeign(['brgy_id']);
        });

        DB::statement("ALTER TABLE projects MODIFY prov_id INT NULL DEFAULT '0'");
        DB::statement("ALTER TABLE projects MODIFY city_id INT NULL DEFAULT '0'");
        DB::statement('ALTER TABLE projects MODIFY brgy_id INT NULL DEFAULT NULL');
    }
};
