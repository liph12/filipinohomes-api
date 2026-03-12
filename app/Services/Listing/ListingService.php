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

    public function updateListing(array $data, Listing $listing, Agent $agent): Listing
    {
        // If subtype + type both provided, validate they match
        if (isset($data['property_subtype_id'], $data['property_type_id'])) {
            $this->validatePropertySubtype(
                $data['property_subtype_id'],
                $data['property_type_id']
            );
        }

        return DB::transaction(function () use ($data, $listing, $agent) {

            $property          = $listing->property;
            $propertyAttribute = $property->propertyAttribute;

            // Update property attributes
            $propertyAttribute->update([
                'bedroom_count'      => $data['bedroom_count']      ?? $propertyAttribute->bedroom_count,
                'bathroom_count'     => $data['bathroom_count']     ?? $propertyAttribute->bathroom_count,
                'garage_count'       => $data['garage_count']       ?? $propertyAttribute->garage_count,
                'lot_area'           => $data['lot_area']           ?? $propertyAttribute->lot_area,
                'floor_area'         => $data['floor_area']         ?? $propertyAttribute->floor_area,
                'property_subtype_id'=> $data['property_subtype_id']?? $propertyAttribute->property_subtype_id,
            ]);

            // Determine property name
            $propertyName = $property->name;
            if (isset($data['is_project']) && $data['is_project'] && isset($data['project']['name'])) {
                $propertyName = $data['project']['name'];
            } elseif (isset($data['name'])) {
                $propertyName = $data['name'];
            }

            // Update property
            $property->update([
                'name'            => $propertyName,
                'address'         => $data['address']         ?? $property->address,
                'photos'          => $data['photos']          ?? $property->photos,
                'amenities'       => $data['amenities']       ?? $property->amenities,
                'description'     => $data['description']     ?? $property->description,
                'geo_coordinates' => $data['geo_coordinates'] ?? $property->geo_coordinates,
                'is_project'      => $data['is_project']      ?? $property->is_project,
                'furnishing_id'   => array_key_exists('furnishing_id', $data)
                                        ? $data['furnishing_id']
                                        : $property->furnishing_id,
            ]);

            // Resolve slug only if name changed
            $slug = $listing->slug;
            if (isset($data['name']) && $data['name'] !== $listing->name) {
                $baseSlug  = Str::slug($data['name']);
                $slugTaken = Listing::where('slug', $baseSlug)
                    ->where('id', '!=', $listing->id)
                    ->exists();

                $slug = $slugTaken ? "{$baseSlug}-{$listing->id}" : $baseSlug;
            }

            // Update listing
            $listing->update([
                'name'          => $data['name']          ?? $listing->name,
                'slug'          => $slug,
                'price'         => $data['price']         ?? $listing->price,
                'visibility'    => $data['visibility']    ?? $listing->visibility,
                'status'        => $data['status']        ?? $listing->status,
                'category_id'   => $data['category_id']  ?? $listing->category_id,
                'is_featured'   => $data['is_featured']  ?? $listing->is_featured,
                'featured_photo'=> isset($data['featured_photo'])
                                    ? (is_array($data['featured_photo'])
                                        ? $data['featured_photo']
                                        : [$data['featured_photo']])
                                    : $listing->featured_photo,
            ]);

            $listing->load(['property', 'category', 'agent']);

            return $listing->fresh();
        });
    }
}