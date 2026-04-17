<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $projectIds = DB::table('projects')
            ->whereNull('latitude')
            ->orWhereNull('longitude')
            ->orWhereRaw("TRIM(CAST(latitude AS CHAR)) = ''")
            ->orWhereRaw("TRIM(CAST(longitude AS CHAR)) = ''")
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return;
        }

        DB::table('properties')
            ->whereIn('project_id', $projectIds)
            ->update(['project_id' => null]);

        DB::table('projects')
            ->whereIn('id', $projectIds)
            ->delete();
    }

    public function down(): void
    {
        // Irreversible cleanup migration.
    }
};
