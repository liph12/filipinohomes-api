<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('agents', 'idx_agents_status')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->index('status', 'idx_agents_status');
            });
        }
        if (!$this->indexExists('agents', 'idx_agents_deleted_at')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->index('deleted_at', 'idx_agents_deleted_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex('idx_agents_status');
            $table->dropIndex('idx_agents_deleted_at');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $indexName]
        );
        return !empty($rows);
    }
};
