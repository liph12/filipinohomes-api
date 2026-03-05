<?php

namespace App\Services\Listing;

use App\Models\Agent;
use App\Models\Listing;
use App\Models\Property;
use App\Models\PropertyAttribute;
use App\Models\PropertySubtype;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListingService
{
    public function createListing(array $data, Agent $agent): Listing
    {
        // Validate subtype belongs to type
        $this->validatePropertySubtype(
            $data['property_subtype_id'],
            $data['property_type_id']
        );

        return DB::transaction(function () use ($data, $agent) {

            // Create property attributes
            $propertyAttribute = $this->createPropertyAttribute($data);

            // Create property (furnishing_id can be NULL)
            $property = $this->createProperty(
                $data,
                $propertyAttribute->id,
                $data['furnishing_id'] ?? null
            );

            // Create listing
            $listing = $this->createListingRecord(
                $data,
                $property->id,
                $data['category_id'] ?? null,
                $agent->id
            );

            $listing->load(['property', 'category', 'agent']);

            return $listing;
        });
    }

    protected function validatePropertySubtype(int $propertySubtypeId, int $propertyTypeId): void
    {
        $propertySubtype = PropertySubtype::findOrFail($propertySubtypeId);

        if ($propertySubtype->property_type_id !== $propertyTypeId) {
            throw new \InvalidArgumentException(
                'Property subtype does not belong to the selected property type'
            );
        }
    }

    protected function createPropertyAttribute(array $data): PropertyAttribute
    {
        return PropertyAttribute::create([
            'bedroom_count' => $data['bedroom_count'] ?? null,
            'bathroom_count' => $data['bathroom_count'] ?? null,
            'garage_count' => $data['garage_count'] ?? null,
            'lot_area' => $data['lot_area'] ?? null,
            'floor_area' => $data['floor_area'] ?? null,
            'property_subtype_id' => $data['property_subtype_id'],
        ]);
    }

    protected function createProperty(
        array $data,
        int $propertyAttributeId,
        ?int $furnishingId
    ): Property {
        // Determine property name based on is_project flag
        $propertyName = $data['name']; // default: listing name

        if (($data['is_project'] ?? false) && isset($data['project']['name'])) {
            $propertyName = $data['project']['name']; // use project name
        }

        return Property::create([
            'name' => $propertyName,
            'address' => $data['address'],
            'photos' => $data['photos'] ?? [],
            'amenities' => $data['amenities'] ?? [],
            'description' => $data['description'] ?? null,
            'geo_coordinates' => $data['geo_coordinates'] ?? null, 
            'is_project' => $data['is_project'] ?? false,
            'property_attribute_id' => $propertyAttributeId,
            'furnishing_id' => $furnishingId,
        ]);
    }

    protected function createListingRecord(
        array $data,
        int $propertyId,
        ?int $categoryId,
        int $agentId
    ): Listing {
        return Listing::create([
            'visibility' => $data['visibility'] ?? 'private',
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'price' => $data['price'],
            'featured_photo' => isset($data['featured_photo'])
                ? (is_array($data['featured_photo']) 
                    ? $data['featured_photo'] 
                    : [$data['featured_photo']]) 
                : null,
            'is_featured' => $data['is_featured'] ?? false,
            'clicks' => 0,
            'property_id' => $propertyId,
            'category_id' => $categoryId,
            'agent_id' => $agentId,
        ]);
    }
}