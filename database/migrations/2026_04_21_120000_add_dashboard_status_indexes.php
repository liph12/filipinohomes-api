<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->index(
                ['deleted_at', 'status_change_date', 'status'],
                'properties_dashboard_status_idx'
            );
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->index(
                ['property_id', 'agent_id', 'deleted_at'],
                'listings_property_agent_deleted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_dashboard_status_idx');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_property_agent_deleted_idx');
        });
    }
};
