<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Proplisting;
use App\Models\PropertyAttribute;
use App\Models\Property;
use App\Models\Amenity;
use App\Models\Furnishing;
use App\Models\Listing;
use Illuminate\Support\Str;
use App\Models\Agent;

class PropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $listings = Proplisting::whereBetween('date_added', ['2024-01-01', '2026-03-10'])
        ->get();

        foreach($listings as $l)
        {
            $agent = Agent::where('user_id',$l->user_id)->first();
            $slug = Str::slug($l->property_title);
            $isExists = Listing::where('slug', $slug)->exists();
            $furnishing = Furnishing::where('name', $l->furnishing)->first();
            $binAmenities = $l->indoor."-".$l->outdoor;
            $binAmenitiesArray = explode("-", $binAmenities);
            $price = (float) str_replace(',', '', $l->announceas == 2 ? $l->rental_rate : $l->propertycost);
            $amenitiesArray = [];

            for($i = 0; $i < count($binAmenitiesArray); $i ++)
            {
                $index = $i + 1;

                if($binAmenitiesArray[$i] == '1')
                {
                    $amenitiesArray[] = Amenity::find($index)->name;
                }
            }

            if($isExists)
            {
                $slug = $slug."-".$l->id;
            }

            if($agent)
            {
                $photosUpdated = [];
                $photos = json_decode($l->gallery) ?? [];

                if(count($photos) > 0)
                {
                    foreach($photos as $p)
                    {
                        $hasDomain = str_contains($p, "https://");
                        $photosUpdated[] = $hasDomain ? $p : "https://s3-ap-southeast-1.amazonaws.com/filipinohomes/".$p;
                    }
                }

                $attribute = PropertyAttribute::create([
                    'bedroom_count' => ($l->bedroom === null || $l->bedroom === '' || $l->bedroom < 0) ? 0 : (float) str_replace(',', '',$l->bedroom),
                    'bathroom_count' => ($l->bathroom === null || $l->bathroom === '' || $l->bathroom < 0) ? 0 : (float) str_replace(',', '',$l->bathroom),
                    'garage_count' => $l->carpark === 'Yes' ? 1 : 0,
                    'lot_area' => ($l->lot_area === null || $l->lot_area === '' || $l->lot_area < 0) ? 0 : (float) str_replace(',', '',$l->lot_area),
                    'floor_area' => ($l->floor_area === null || $l->floor_area === '' || $l->floor_area < 0) ? 0 :  (float) str_replace(',', '',$l->floor_area),
                    'property_subtype_id' => $l->property_type_id
                ]);
    
                $property = Property::create([
                    'name' => $l->condo_name === null ? $l->property_title : $l->condo_name,
                    'address' => $l->mapaddress,
                    'photos' => $photosUpdated,
                    'amenities' => $amenitiesArray,
                    'description' => $l->description,
                    'address_id' => $l->brgy_id,
                    'geo_coordinates' => [
                        'lat' => (float) $l->latitude,
                        'lng' => (float) $l->longitude
                    ],
                    'is_project' => $l->condo_name !== null,
                    'property_attribute_id' => $attribute->id,
                    'furnishing_id' => $furnishing->id ?? 3,
                ]);
    
                Listing::create([
                    'code' => $l->propcode,
                    'name' => $l->property_title,
                    'slug' => $slug,
                    'price' => $price,
                    'featured_photo' => [!str_contains($l->featured_photo, "https://") ? "https://s3-ap-southeast-1.amazonaws.com/filipinohomes/".$l->featured_photo : $l->featured_photo],
                    'visibility' => $l->listing_status,
                    'is_featured' => false,
                    'clicks' => $l->views === null ? 0 : $l->views,
                    'property_id' => $property->id,
                    'category_id' => $l->announceas,
                    'agent_id' => $agent->id
                ]);
            }
        }
    }
}
