<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OldToNewPropertiesSeeder extends Seeder
{
    const DEFAULT_ATTRIBUTE_ID = 5000;
    const DEFAULT_FURNISHING_ID = 5000;

    public function run()
    {
        // Load old JSON data
        $oldProperties = json_decode(
            file_get_contents(database_path('old_properties.json')),
            true
        );

        /**
         * --------------------------------------------------
         * Create DEFAULT rows ONCE
         * --------------------------------------------------
         */

        DB::table('property_attributes')->updateOrInsert(
            ['id' => self::DEFAULT_ATTRIBUTE_ID],
            [
                'bedroom_count' => 0,
                'bathroom_count' => 0,
                'garage_count' => 0,
                'lot_area' => 0,
                'floor_area' => 0,
                'property_subtype_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('furnishings')->updateOrInsert(
            ['id' => self::DEFAULT_FURNISHING_ID],
            [
                'name' => 'Default',
                'status' => 'Default',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        /**
         * --------------------------------------------------
         * Insert properties
         * --------------------------------------------------
         */
        foreach ($oldProperties['data'] as $old) {

            DB::table('properties')->insert([
                'id' => $old['id'],
                'name' => $old['name'],
                'address' => $old['complete_address'] ?? '',
                'photos' => json_encode(
                    $old['photos_url']
                        ?: ($old['featured_photo'] ? [$old['featured_photo']] : [])
                ),
                'amenities' => json_encode($old['feat_facilities']),
                'description' => trim(
                    $old['about'] . ' ' .
                    $old['project_feature'] . ' ' .
                    $old['unit_type'] . ' ' .
                    $old['project_feature']
                ),
                'geo_coordinates' => json_encode([
                    'lat' => $old['latitude'],
                    'lng' => $old['longitude'],
                ]),
                'is_project' => true,
                'property_attribute_id' => self::DEFAULT_ATTRIBUTE_ID,
                'furnishing_id' => self::DEFAULT_FURNISHING_ID,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
