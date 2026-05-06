<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // conversations: agent_user_id + status — covers both inquiry COUNT subqueries
        if (Schema::hasTable('conversations') && !$this->indexExists('conversations', 'idx_conv_agent_status')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->index(['agent_user_id', 'status'], 'idx_conv_agent_status');
            });
        }

        // listings: agent_id + visibility — covers listings_count, public/private counts
        if (Schema::hasTable('listings') && !$this->indexExists('listings', 'idx_listings_agent_visibility')) {
            Schema::table('listings', function (Blueprint $table) {
                $table->index(['agent_id', 'visibility'], 'idx_listings_agent_visibility');
            });
        }

        // listings: agent_id + property_id — covers the JOIN to properties for sold/rented/leased
        if (Schema::hasTable('listings') && !$this->indexExists('listings', 'idx_listings_agent_property')) {
            Schema::table('listings', function (Blueprint $table) {
                $table->index(['agent_id', 'property_id'], 'idx_listings_agent_property');
            });
        }

        // properties: status — used in the JOIN filter for sold/rented/leased
        if (Schema::hasTable('properties') && !$this->indexExists('properties', 'idx_properties_status')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->index('status', 'idx_properties_status');
            });
        }

        // agents: member_since — used when sorting by member_since
        if (Schema::hasTable('agents') && !$this->indexExists('agents', 'idx_agents_member_since')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->index('member_since', 'idx_agents_member_since');
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'conversations' => 'idx_conv_agent_status',
            'listings'      => ['idx_listings_agent_visibility', 'idx_listings_agent_property'],
            'properties'    => 'idx_properties_status',
            'agents'        => 'idx_agents_member_since',
        ];

        foreach ($drops as $table => $indexes) {
            foreach ((array) $indexes as $index) {
                if (Schema::hasTable($table) && $this->indexExists($table, $index)) {
                    Schema::table($table, function (Blueprint $t) use ($index) {
                        $t->dropIndex($index);
                    });
                }
            }
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
