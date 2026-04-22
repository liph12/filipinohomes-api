<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->index(
                ['deleted_at', 'prov_id', 'city_id'],
                'projects_deleted_prov_city_idx'
            );
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->index(
                ['property_id', 'visibility', 'deleted_at', 'category_id'],
                'listings_property_visibility_deleted_category_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_deleted_prov_city_idx');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_property_visibility_deleted_category_idx');
        });
    }
};
