<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function moveColumn(string $column, string $definition, string $after): void
    {
        DB::statement(sprintf(
            'ALTER TABLE projects MODIFY %s %s AFTER %s',
            $column,
            $definition,
            $after
        ));
    }

    public function up(): void
    {
        $this->moveColumn('featured_photo', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL', 'slug');
        $this->moveColumn('photos_url', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL', 'featured_photo');
        $this->moveColumn('complete_address', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'photos_url');
        $this->moveColumn('street', 'VARCHAR(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL', 'complete_address');
        $this->moveColumn('brgy_id', 'INT NULL DEFAULT NULL', 'street');
        $this->moveColumn('city_id', "INT NULL DEFAULT '0'", 'brgy_id');
        $this->moveColumn('prov_id', "INT NULL DEFAULT '0'", 'city_id');
        $this->moveColumn('mapaddress', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'prov_id');
        $this->moveColumn('latitude', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'mapaddress');
        $this->moveColumn('longitude', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'latitude');
        $this->moveColumn('views', 'INT NULL DEFAULT NULL', 'longitude');
        $this->moveColumn('created_at', 'TIMESTAMP NULL DEFAULT NULL', 'views');
        $this->moveColumn('updated_at', 'TIMESTAMP NULL DEFAULT NULL', 'created_at');
        $this->moveColumn('deleted_at', 'TIMESTAMP NULL DEFAULT NULL', 'updated_at');
        $this->moveColumn('deleted_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'deleted_at');
        $this->moveColumn('created_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'deleted_by');
        $this->moveColumn('updated_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'created_by');
        $this->moveColumn('devid', 'VARCHAR(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL', 'updated_by');
    }

    public function down(): void
    {
        $this->moveColumn('devid', 'VARCHAR(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL', 'id');
        $this->moveColumn('prov_id', "INT NULL DEFAULT '0'", 'prop_type_id');
        $this->moveColumn('city_id', "INT NULL DEFAULT '0'", 'prov_id');
        $this->moveColumn('brgy_id', 'INT NULL DEFAULT NULL', 'city_id');
        $this->moveColumn('street', 'VARCHAR(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL', 'brgy_id');
        $this->moveColumn('mapaddress', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'feat_facilities');
        $this->moveColumn('latitude', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'mapaddress');
        $this->moveColumn('longitude', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'latitude');
        $this->moveColumn('complete_address', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL', 'longitude');
        $this->moveColumn('views', 'INT NULL DEFAULT NULL', 'added_by');
        $this->moveColumn('featured_photo', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL', 'new');
        $this->moveColumn('photos_url', 'TEXT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL', 'featured_photo');
        $this->moveColumn('created_at', 'TIMESTAMP NULL DEFAULT NULL', 'photos_url');
        $this->moveColumn('updated_at', 'TIMESTAMP NULL DEFAULT NULL', 'created_at');
        $this->moveColumn('deleted_at', 'TIMESTAMP NULL DEFAULT NULL', 'updated_at');
        $this->moveColumn('deleted_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'deleted_at');
        $this->moveColumn('created_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'deleted_by');
        $this->moveColumn('updated_by', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'created_by');
    }
};
