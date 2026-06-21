<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inquiry Analytics drill queries filter chats.type='listing' + a
        // chats.created_at date range FIRST, then join outward. The existing
        // chats(type, type_id) index doesn't cover the date range; a
        // composite (type, created_at) lets the planner start from the
        // smallest driving set. Join FKs (listings.property_id,
        // properties.property_attribute_id/address_id, barangays.city_id,
        // cities.province_id, listings.category_id, users.role_id) are
        // already indexed by their FK constraints / prior migrations.
        if (Schema::hasTable('chats') && !$this->indexExists('chats', 'chats_type_created_at_index')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index(['type', 'created_at'], 'chats_type_created_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chats') && $this->indexExists('chats', 'chats_type_created_at_index')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropIndex('chats_type_created_at_index');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );
        return !empty($rows);
    }
};
