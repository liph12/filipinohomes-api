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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
class PropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */

    private function parseDateOrNow($date)
    {
        if (empty($date)) {
            return Carbon::now();
        }

        $formats = [
            'm-d-Y',
            'Y-m-d',
            'm/d/Y',
            'd-m-Y',
            'Y-m-d H:i:s',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $date);
            } catch (\Exception $e) {
                // try next format
            }
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return Carbon::now();
        }
    }

public function run(): void
{
    DB::disableQueryLog();

    // Pre-load all lookups ONCE
    $allAgents     = Agent::pluck('id', 'user_id');        // user_id => agent id
    $allFurnishing = Furnishing::pluck('id', 'name');      // name => furnishing id
    $allAmenities  = Amenity::orderBy('id')->pluck('name'); // index-based
    $usedSlugs     = Listing::pluck('slug')->flip();        // slug => true
    $usedCodes     = Listing::pluck('code')->flip();        // code => true

    $startDate = '2020-01-01';
    $endDate   = Carbon::now()->toDateString();

    Proplisting::whereBetween('date_added', [$startDate, $endDate])
        ->chunk(500, function ($listings) use ($allAgents, $allFurnishing, $allAmenities, &$usedSlugs, &$usedCodes) {

            $attributeData = [];
            $propertyData  = [];
            $listingData   = [];

            foreach ($listings as $l) {

                $agentId = $allAgents[$l->user_id] ?? null;
                if (!$agentId) continue;

                // Slug — no DB query
                $baseSlug  = Str::slug($l->property_title);
                $candidate = empty($baseSlug) ? 'NON-' . $l->id : $baseSlug;
                if (isset($usedSlugs[$candidate])) {
                    $candidate = $baseSlug . '-' . $l->id;
                }
                $usedSlugs[$candidate] = true;

                // Code — no DB query
                $code = empty(trim($l->propcode ?? '')) ? 'NON' : $l->propcode;
                if (isset($usedCodes[$code])) {
                    $code = 'NON-' . $l->id;
                }
                $usedCodes[$code] = true;

                // Furnishing — no DB query
                $furnishingId = $allFurnishing[$l->furnishing] ?? 3;

                // Amenities — no DB query
                $binAmenitiesArray = explode('-', $l->indoor . '-' . $l->outdoor);
                $amenitiesArray    = [];
                foreach ($binAmenitiesArray as $i => $bit) {
                    if ($bit === '1' && isset($allAmenities[$i])) {
                        $amenitiesArray[] = $allAmenities[$i];
                    }
                }

                // Photos
                $photosUpdated = [];
                foreach (json_decode($l->gallery) ?? [] as $p) {
                    if (empty($p)) continue;
                    $photosUpdated[] = str_contains($p, 'https://')
                        ? $p
                        : 'https://s3-ap-southeast-1.amazonaws.com/filipinohomes/' . $p;
                }

                $featuredPhoto = $l->featured_photo
                    ? [str_contains($l->featured_photo, 'https://')
                        ? $l->featured_photo
                        : 'https://s3-ap-southeast-1.amazonaws.com/filipinohomes/' . $l->featured_photo]
                    : [];

                // If featured_photo is empty but gallery has photos, use the first gallery photo
                if (empty($featuredPhoto) && !empty($photosUpdated)) {
                    $featuredPhoto = [$photosUpdated[0]];
                }

                if (empty($photosUpdated) && empty($featuredPhoto)) continue;

                $createdAt = date('Y-m-d H:i:s', strtotime($l->date_added));
                $updatedAt = $this->parseDateOrNow($l->date_updated);
                $price     = (float) str_replace(',', '', $l->announceas == 2 ? $l->rental_rate : $l->propertycost);

                $attributeData[] = [
                    'id'                  => $l->id,
                    'bedroom_count'       => max(0, (float) str_replace(',', '', $l->bedroom  ?? 0)),
                    'bathroom_count'      => max(0, (float) str_replace(',', '', $l->bathroom ?? 0)),
                    'garage_count'        => $l->carpark === 'Yes' ? 1 : 0,
                    'lot_area'            => max(0, (float) str_replace(',', '', $l->lot_area   ?? 0)),
                    'floor_area'          => max(0, (float) str_replace(',', '', $l->floor_area ?? 0)),
                    'property_subtype_id' => $l->property_type_id,
                    'created_at'          => $createdAt,
                    'updated_at'          => $updatedAt,
                ];

                $propertyData[] = [
                    'id'                   => $l->id,
                    'name'                 => $l->condo_name ?? $l->property_title,
                    'address'              => $l->mapaddress,
                    'photos'               => json_encode($photosUpdated),
                    'amenities'            => json_encode($amenitiesArray),
                    'description'          => $l->description,
                    'address_id'           => $l->brgy_id,
                    'geo_coordinates'      => json_encode(['lat' => (float) $l->latitude, 'lng' => (float) $l->longitude]),
                    'is_project'           => $l->condo_name !== null ? 1 : 0,
                    'property_attribute_id'=> $l->id,
                    'furnishing_id'        => $furnishingId,
                    'created_at'           => $createdAt,
                    'updated_at'           => $updatedAt,
                ];

                $listingData[] = [
                    'code'           => $code,
                    'name'           => $l->property_title,
                    'slug'           => $candidate,
                    'price'          => $price,
                    'featured_photo' => json_encode($featuredPhoto),
                    'visibility'     => $l->listing_status,
                    'is_featured'    => 0,
                    'clicks'         => $l->views ?? 0,
                    'property_id'    => $l->id,
                    'category_id'    => $l->announceas,
                    'agent_id'       => $agentId,
                    'created_at'     => $createdAt,
                    'updated_at'     => $updatedAt,
                ];
            }

            if (!empty($attributeData)) DB::table('property_attributes')->insertOrIgnore($attributeData);
            if (!empty($propertyData))  DB::table('properties')->insertOrIgnore($propertyData);
            if (!empty($listingData))   DB::table('listings')->insertOrIgnore($listingData);
        });
}
}
