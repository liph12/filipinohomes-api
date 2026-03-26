<?php

namespace App\Services\Listing;

use App\Models\Agent;
use App\Models\Listing;
use App\Models\Property;
use App\Models\PropertyAttribute;
use App\Models\PropertySubtype;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingService
{
    public function createListing(array $data, Agent $agent): Listing
    {
        $this->validatePropertySubtype(
            $data['property_subtype_id'],
            $data['property_type_id']
        );

        return DB::transaction(function () use ($data, $agent) {

            $propertyAttribute = $this->createPropertyAttribute($data);

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

            if (empty($data['seo_tags'])) {
                $this->syncSeoTags($listing);
            }

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
            'bedroom_count'      => $data['bedroom_count'] ?? null,
            'bathroom_count'     => $data['bathroom_count'] ?? null,
            'garage_count'       => $data['garage_count'] ?? null,
            'lot_area'           => $data['lot_area'] ?? null,
            'floor_area'         => $data['floor_area'] ?? null,
            'property_subtype_id' => $data['property_subtype_id'],
        ]);
    }

    protected function createProperty(
        array $data,
        int $propertyAttributeId,
        ?int $furnishingId
    ): Property {
        $propertyName = $data['name'];

        if (($data['is_project'] ?? false) && isset($data['project']['name'])) {
            $propertyName = $data['project']['name'];
        }

        return Property::create([
            'name'                 => $propertyName,
            'address'              => $data['address'],
            'photos'               => $data['photos'] ?? [],
            'amenities'            => $data['amenities'] ?? [],
            'description'          => $data['description'] ?? null,
            'geo_coordinates'      => $data['geo_coordinates'] ?? null,
            'ats_expiration_date'  => $data['ats_expiration_date'] ?? null,
            'is_project'           => $data['is_project'] ?? false,
            'property_attribute_id' => $propertyAttributeId,
            'furnishing_id'        => $furnishingId,
            'address_id'           => $data['address_id'] ?? null,
        ]);
    }

    protected function createListingRecord(
        array $data,
        int $propertyId,
        ?int $categoryId,
        int $agentId
    ): Listing {
        return Listing::create([
            'visibility'    => $data['visibility'] ?? 'private',
            'name'          => $data['name'],
            'slug'          => $data['slug'] ?? Str::slug($data['name']),
            'price'         => $data['price'],
            'featured_photo' => isset($data['featured_photo'])
                ? (is_array($data['featured_photo'])
                    ? $data['featured_photo']
                    : [$data['featured_photo']])
                : null,
            'is_featured'   => $data['is_featured'] ?? false,
            'clicks'        => 0,
            'property_id'   => $propertyId,
            'category_id'   => $categoryId,
            'agent_id'      => $agentId,
            'seo_tags'      => !empty($data['seo_tags']) ? $data['seo_tags'] : null,
        ]);
    }

    protected function syncSeoTags(Listing $listing): void
    {
        try {
            $response = Http::get(
                'https://api.leuteriorealty.com/fh/v2/public/api/generate-description-tags/' . urlencode($listing->name)
            );

            if ($response->failed()) {
                Log::warning('SEO tag generation failed', [
                    'listing_id' => $listing->id,
                    'status'     => $response->status(),
                ]);
                return;
            }

            $data = $response->json();
            $tags = $data['tags'] ?? $data['data']['tags'] ?? [];

            if (!empty($tags)) {
                $listing->update(['seo_tags' => $tags]);
            }
        } catch (\Throwable $e) {
            Log::error('syncSeoTags exception', [
                'listing_id' => $listing->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function updateListing(array $data, Listing $listing, Agent $agent): Listing
    {
        if (isset($data['property_subtype_id'], $data['property_type_id'])) {
            $this->validatePropertySubtype(
                $data['property_subtype_id'],
                $data['property_type_id']
            );
        }

        return DB::transaction(function () use ($data, $listing, $agent) {
            $property          = $listing->property;
            $propertyAttribute = $property->propertyAttribute;

            $attributeData = array_intersect_key($data, array_flip([
                'bedroom_count', 'bathroom_count', 'garage_count',
                'lot_area', 'floor_area', 'property_subtype_id'
            ]));
            $propertyAttribute->update($attributeData);

            $propertyName = $property->name;
            if (isset($data['is_project']) && $data['is_project'] && isset($data['project']['name'])) {
                $propertyName = $data['project']['name'];
            } elseif (isset($data['name'])) {
                $propertyName = $data['name'];
            }

            $propertyFields = [
                'address', 'photos', 'amenities', 'description',
                'geo_coordinates', 'ats_expiration_date', 'is_project', 'furnishing_id','address_id'
            ];
            $propertyData         = array_intersect_key($data, array_flip($propertyFields));
            $propertyData['name'] = $propertyName;
            $property->update($propertyData);

            $slug = $listing->slug;
            if (isset($data['name']) && $data['name'] !== $listing->name) {
                $baseSlug  = Str::slug($data['name']);
                $slugTaken = Listing::where('slug', $baseSlug)
                    ->where('id', '!=', $listing->id)
                    ->exists();

                $slug = $slugTaken ? "{$baseSlug}-{$listing->id}" : $baseSlug;
            }

            $listingFields = [
                'name', 'price', 'visibility', 'status',
                'category_id', 'is_featured', 'seo_tags'
            ];
            $listingData          = array_intersect_key($data, array_flip($listingFields));
            $listingData['slug']  = $slug;

            if (isset($data['featured_photo'])) {
                $listingData['featured_photo'] = is_array($data['featured_photo'])
                    ? $data['featured_photo']
                    : [$data['featured_photo']];
            }

            $listing->update($listingData);

            if (empty($listing->seo_tags) && empty($data['seo_tags'])) {
                $this->syncSeoTags($listing);
                $listing->refresh();
            }

            $wasActuallyUpdated = $listing->wasChanged() ||
                                  $property->wasChanged()  ||
                                  $propertyAttribute->wasChanged();

            $listing->load(['property.propertyAttribute.subtype.type', 'category', 'agent']);
            $listing->was_actually_updated = $wasActuallyUpdated;

            return $listing;
        });
    }
}