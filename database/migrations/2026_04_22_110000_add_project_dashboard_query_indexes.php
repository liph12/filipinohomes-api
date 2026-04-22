<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->index(
                ['property_id', 'deleted_at', 'category_id'],
                'listings_property_deleted_category_idx'
            );
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->index(
                ['is_project', 'deleted_at', 'project_id', 'address_id'],
                'properties_project_dashboard_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_property_deleted_category_idx');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_project_dashboard_idx');
        });
    }
};
