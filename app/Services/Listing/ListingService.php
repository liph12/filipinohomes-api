<?php

namespace App\Services\Listing;

use App\Models\Agent;
use App\Models\Listing;
use App\Models\Property;
use App\Models\PropertyAttribute;
use App\Models\Project;
use App\Models\PropertySubtype;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\NearbyFacility;

class ListingService
{
    public function createListing(array $data, Agent $agent): Listing
    {
        $this->validatePropertySubtype(
            $data['property_subtype_id'],
            $data['property_type_id']
        );

        $listing = DB::transaction(function () use ($data, $agent) {

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

            // Persist nearby facilities in the new schema (one row per property with JSON columns)
            $facilitiesPayload = $this->buildNearbyFacilitiesPayload($data);
            if (!empty($facilitiesPayload)) {
                NearbyFacility::updateOrCreate(
                    ['property_id' => $property->id],
                    $facilitiesPayload
                );
            }

            return $listing;
        });

        Cache::forget('projects_db');

        return $listing;
    }

    /**
     * Normalize incoming nearby facilities from either 'nearby_facilities' (new)
     * or legacy 'nearby_places' (old) into an associative array matching
     * nearby_facilities table columns.
     */
    protected function buildNearbyFacilitiesPayload(array $data): array
    {
        $allowed = [
            'school', 'hospital', 'clinic', 'pharmacy', 'fire_station', 'police_station'
        ];

        // New flexible format handling under 'nearby_facilities'
        if (!empty($data['nearby_facilities']) && is_array($data['nearby_facilities'])) {
            $nf = $data['nearby_facilities'];
            $payload = [];

            foreach ($allowed as $key) {
                if (!isset($nf[$key])) {
                    continue;
                }

                $value = $nf[$key];

                // Case 1: Already an array of objects
                if (is_array($value) && $this->isList($value)) {
                    $payload[$key] = [];
                    foreach ($value as $item) {
                        $payload[$key][] = $this->normalizeFacilityItem($item);
                    }
                    continue;
                }

                // Case 2: Single object describing facility
                if (is_array($value) && !$this->isList($value)) {
                    $payload[$key] = [$this->normalizeFacilityItem($value)];
                    continue;
                }

                // Case 3: Simple string e.g., 'hospital' => 'Community Hospital'
                if (is_string($value)) {
                    $commonGeo = $nf['geo_coordinates'] ?? null; // optional sibling
                    $commonDistance = $nf['distance'] ?? null;     // optional sibling
                    $payload[$key] = [
                        $this->normalizeFacilityItem([
                            'name'            => $value,
                            'geo_coordinates' => $commonGeo,
                            'distance'        => $commonDistance,
                        ])
                    ];
                    continue;
                }
            }

            return array_filter($payload, fn($v) => !empty($v));
        }

        // Legacy format: 'nearby_places' => [{type, name, distance_meters, geo_coordinates{lat,lng}, address}]
        if (!empty($data['nearby_places']) && is_array($data['nearby_places'])) {
            $grouped = [
                'school' => [], 'hospital' => [], 'clinic' => [],
                'pharmacy' => [], 'fire_station' => [], 'police_station' => [],
            ];

            foreach ($data['nearby_places'] as $p) {
                $type = $p['type'] ?? '';
                if ($type === 'police') { // map old to new
                    $type = 'police_station';
                }
                if (!array_key_exists($type, $grouped)) {
                    continue;
                }

                $coords = $p['geo_coordinates'] ?? [];
                $grouped[$type][] = [
                    'name'            => $p['name'] ?? null,
                    'distance_meters' => $this->parseDistanceToMeters($p['distance'] ?? $p['distance_meters'] ?? null),
                    'lat'             => isset($coords['lat']) ? (float) $coords['lat'] : null,
                    'lng'             => isset($coords['lng']) ? (float) $coords['lng'] : null,
                    'address'         => $p['address'] ?? null,
                ];
            }

            // Remove empty groups
            return array_filter($grouped, fn ($v) => !empty($v));
        }

        return [];
    }

    protected function normalizeFacilityItem(array $item): array
    {
        $coords = $item['geo_coordinates'] ?? $item['coords'] ?? null;
        $lat = null;
        $lng = null;
        if (is_array($coords)) {
            $lat = isset($coords['lat']) ? (float) $coords['lat'] : null;
            $lng = isset($coords['lng']) ? (float) $coords['lng'] : null;
        } else {
            $lat = isset($item['lat']) ? (float) $item['lat'] : null;
            $lng = isset($item['lng']) ? (float) $item['lng'] : null;
        }

        return [
            'name'            => $item['name'] ?? $item['value'] ?? null,
            'distance_meters' => $this->parseDistanceToMeters($item['distance'] ?? $item['distance_meters'] ?? null),
            'lat'             => $lat,
            'lng'             => $lng,
            'address'         => $item['address'] ?? null,
        ];
    }

    protected function parseDistanceToMeters($distance): ?int
    {
        if ($distance === null || $distance === '') {
            return null;
        }

        if (is_numeric($distance)) {
            return (int) round((float) $distance);
        }

        if (is_string($distance)) {
            $s = trim(strtolower($distance));
            // Replace commas, handle "1.2 km", "750 m", etc.
            $s = str_replace(',', '', $s);
            if (str_ends_with($s, 'km')) {
                $num = (float) trim(substr($s, 0, -2));
                return (int) round($num * 1000);
            }
            if (str_ends_with($s, 'm')) {
                $num = (float) trim(substr($s, 0, -1));
                return (int) round($num);
            }
            // Fallback: extract leading number
            if (preg_match('/([0-9]+(\.[0-9]+)?)/', $s, $m)) {
                $num = (float) $m[1];
                // Heuristic: if mentions 'km' anywhere, treat as km
                if (str_contains($s, 'km')) {
                    return (int) round($num * 1000);
                }
                return (int) round($num);
            }
        }

        return null;
    }

    protected function isList(array $arr): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($arr);
        }
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
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
        $project = $this->resolveProject($data);
        $propertyName = $project?->name ?? $data['name'];

        if (($data['is_project'] ?? false) && isset($data['project']['name']) && !$project) {
            $propertyName = $data['project']['name'];
        }

        return Property::create([
            'name'                 => $propertyName,
            'project_id'           => $project?->id,
            'address'              => $data['address'],
            'photos'               => $data['photos'] ?? [],
            'amenities'            => $data['amenities'] ?? [],
            'description'          => $data['description'] ?? null,
            'geo_coordinates'      => $data['geo_coordinates'] ?? null,
            'ats_expiration_date'  => $data['ats_expiration_date'] ?? null,
            'ats_attachments'      => $data['ats_attachments'] ?? [
                'photos' => [],
                'documents' => [],    
            ],
            'ats_remarks'          => $data['ats_remarks'] ?? null,
            'ats_status'           => 'pending',
            'is_project'           => $data['is_project'] ?? false,
            'property_attribute_id' => $propertyAttributeId,
            'furnishing_id'        => $furnishingId,
            'address_id'           => $data['address_id'] ?? null,
        ]);
    }

    protected function resolveProject(array $data): ?Project
    {
        if (!($data['is_project'] ?? false)) {
            return null;
        }

        $projectId = $data['project_id'] ?? data_get($data, 'project.id');
        if (!empty($projectId)) {
            return Project::find($projectId);
        }

        $projectName = trim((string) data_get($data, 'project.name', ''));
        if ($projectName === '') {
            return null;
        }

        return Project::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($projectName)])
            ->orderBy('id')
            ->first();
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

        $updatedListing = DB::transaction(function () use ($data, $listing, $agent) {
            $property          = $listing->property;
            $propertyAttribute = $property->propertyAttribute;
            $project           = $this->resolveProject($data);
            $isProject         = array_key_exists('is_project', $data)
                ? (bool) $data['is_project']
                : (bool) $property->is_project;

            $attributeData = array_intersect_key($data, array_flip([
                'bedroom_count', 'bathroom_count', 'garage_count',
                'lot_area', 'floor_area', 'property_subtype_id'
            ]));
            $propertyAttribute->update($attributeData);

            $propertyName = $property->name;
            if ($isProject && $project) {
                $propertyName = $project->name;
            } elseif ($isProject && isset($data['project']['name'])) {
                $propertyName = $data['project']['name'];
            } elseif (isset($data['name'])) {
                $propertyName = $data['name'];
            }

            $propertyFields = [
                'address', 'photos', 'amenities', 'description',
                'geo_coordinates', 'ats_expiration_date', 'ats_attachments', 'ats_remarks', 'is_project', 'furnishing_id','address_id'
            ];
            $propertyData         = array_intersect_key($data, array_flip($propertyFields));
            $propertyData['name'] = $propertyName;

            if (!$isProject) {
                $propertyData['project_id'] = null;
            } elseif ($project) {
                $propertyData['project_id'] = $project->id;
            } elseif (array_key_exists('project_id', $data) && $data['project_id'] === null) {
                $propertyData['project_id'] = null;
            }

            if (array_key_exists('ats_attachments', $data)) {
                $incoming = $data['ats_attachments'];
                $existing = $property->ats_attachments;
                if ($this->attachmentsChanged($existing, $incoming)) {
                    $propertyData['ats_status'] = 'pending';
                    // Reset reviewer when going back to pending
                    $propertyData['reviewed_by'] = null;
                }
            }

            // If ats_status is explicitly provided and changed, record reviewer
            if (isset($data['ats_status'])) {
                $incomingStatus = $data['ats_status'];
                if ($incomingStatus !== $property->ats_status) {
                    $propertyData['ats_status'] = $incomingStatus;
                    $propertyData['reviewed_by'] = Auth::id();
                }
            }
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

            // Update nearby facilities if provided
            $facilitiesPayload = $this->buildNearbyFacilitiesPayload($data);
            if (!empty($facilitiesPayload)) {
                NearbyFacility::updateOrCreate(
                    ['property_id' => $property->id],
                    $facilitiesPayload
                );
            }

            $wasActuallyUpdated = $listing->wasChanged() ||
                                  $property->wasChanged()  ||
                                  $propertyAttribute->wasChanged();

            $listing->load(['property.propertyAttribute.subtype.type', 'category', 'agent']);
            $listing->was_actually_updated = $wasActuallyUpdated;

            return $listing;
        });

        Cache::forget('projects_db');

        return $updatedListing;
    }

    /**
     * Determine if ATS attachments changed vs existing, treating null/empty as same and comparing deeply.
     */
    protected function attachmentsChanged($existing, $incoming): bool
    {
        $normExisting = $this->normalizeAttachments($existing);
        $normIncoming = $this->normalizeAttachments($incoming);

        return $this->stableHash($normExisting) !== $this->stableHash($normIncoming);
    }

    protected function normalizeAttachments($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        $result = [
            'photos'    => [],
            'documents' => [],
        ];

        if (is_array($value)) {
            $result['photos']    = isset($value['photos']) && is_array($value['photos']) ? array_values($value['photos']) : [];
            $result['documents'] = isset($value['documents']) && is_array($value['documents']) ? array_values($value['documents']) : [];
        }

        // Sort to ensure order-insensitive comparison
        $this->deepSort($result['photos']);
        $this->deepSort($result['documents']);

        return $result;
    }

    protected function deepSort(array &$arr): void
    {
        usort($arr, function ($a, $b) {
            $ha = is_array($a) ? json_encode($this->deepSortedCopy($a)) : json_encode($a);
            $hb = is_array($b) ? json_encode($this->deepSortedCopy($b)) : json_encode($b);
            return $ha <=> $hb;
        });
    }

    protected function deepSortedCopy($val)
    {
        if (!is_array($val)) return $val;
        $copy = $val;
        foreach ($copy as &$v) {
            if (is_array($v)) $v = $this->deepSortedCopy($v);
        }
        // Normalize list ordering
        if ($this->isList($copy)) {
            usort($copy, function ($a, $b) {
                $ha = json_encode($a);
                $hb = json_encode($b);
                return $ha <=> $hb;
            });
        } else {
            ksort($copy);
        }
        return $copy;
    }

    protected function stableHash($val): string
    {
        return md5(json_encode($this->deepSortedCopy($val)) ?: '');
    }
}
