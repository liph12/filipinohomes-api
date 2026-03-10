<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\PropertyType;
use App\Models\PropertySubtype;

class PropertySubtypeSeeder extends Seeder
{
    public function run(): void
    {
        $proptypes = array(
            array('id' => '1','name' => 'Apartment','property_type' => 'House','TOTAL_LISTINGS' => '110'),
            array('id' => '2','name' => 'Townhouse','property_type' => 'House','TOTAL_LISTINGS' => '257'),
            array('id' => '3','name' => 'House and Lot','property_type' => 'House','TOTAL_LISTINGS' => '1794'),
            array('id' => '7','name' => 'Boarding House','property_type' => 'House','TOTAL_LISTINGS' => '11'),
            array('id' => '9','name' => 'Penthouse','property_type' => 'Condominium','TOTAL_LISTINGS' => '19'),
            array('id' => '10','name' => 'Retirement House','property_type' => 'House','TOTAL_LISTINGS' => '10'),
            array('id' => '11','name' => 'Studio','property_type' => 'Condominium','TOTAL_LISTINGS' => '738'),
            array('id' => '13','name' => 'Warehouse','property_type' => 'Commercial','TOTAL_LISTINGS' => '42'),
            array('id' => '17','name' => 'Agricultural Lot','property_type' => 'Land','TOTAL_LISTINGS' => '316'),
            array('id' => '23','name' => 'BPO','property_type' => 'Commercial','TOTAL_LISTINGS' => '12'),
            array('id' => '28','name' => 'Pension House','property_type' => 'House','TOTAL_LISTINGS' => '1'),
            array('id' => '30','name' => 'Office','property_type' => 'Commercial','TOTAL_LISTINGS' => '46'),
            array('id' => '31','name' => 'Island','property_type' => 'Land','TOTAL_LISTINGS' => '3'),
            array('id' => '34','name' => '1 Bedroom','property_type' => 'Condominium','TOTAL_LISTINGS' => '436'),
            array('id' => '35','name' => '2 Bedrooms','property_type' => 'Condominium','TOTAL_LISTINGS' => '267'),
            array('id' => '38','name' => '3 Bedrooms','property_type' => 'Condominium','TOTAL_LISTINGS' => '52'),
            array('id' => '39','name' => '4 Bedrooms','property_type' => 'Condominium','TOTAL_LISTINGS' => '5'),
            array('id' => '40','name' => 'Loft','property_type' => 'Condominium','TOTAL_LISTINGS' => '14'),
            array('id' => '42','name' => 'Beach House / Resort','property_type' => 'House','TOTAL_LISTINGS' => '47'),
            array('id' => '43','name' => 'Residential Lot','property_type' => 'Land','TOTAL_LISTINGS' => '898'),
            array('id' => '44','name' => 'Commercial Lot','property_type' => 'Land','TOTAL_LISTINGS' => '243'),
            array('id' => '45','name' => 'Memorial','property_type' => 'Land','TOTAL_LISTINGS' => '6'),
            array('id' => '46','name' => 'Beach Lot','property_type' => 'Land','TOTAL_LISTINGS' => '74'),
            array('id' => '47','name' => 'Building','property_type' => 'Commercial','TOTAL_LISTINGS' => '75'),
            array('id' => '48','name' => 'Industrial Lot','property_type' => 'Land','TOTAL_LISTINGS' => '20'),
            array('id' => '49','name' => 'Hotel','property_type' => 'Commercial','TOTAL_LISTINGS' => '14'),
            array('id' => '50','name' => 'Space','property_type' => 'Commercial','TOTAL_LISTINGS' => '80')
          );
        
        foreach($proptypes as $p)
        {
            $type = PropertyType::where('name', $p['property_type'])->first();
            PropertySubtype::create([
                'id' => intval($p['id']),
                'name' => $p['name'],
                'property_type_id' => $type->id,
            ]);
        }

        $this->command->info('Property subtypes seeded successfully with fixed IDs!');
    }
}
