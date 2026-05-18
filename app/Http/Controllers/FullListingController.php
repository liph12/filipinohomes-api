<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Models\Listing;
use App\Services\Listing\ListingService;
use Illuminate\Http\JsonResponse;
use App\Http\Middleware\RoleMiddleware;
class FullListingController extends Controller
{
    public function __construct(protected ListingService $listingService)
    {
        $this->middleware('auth:sanctum'); 
        $this->middleware(RoleMiddleware::class . ':agent,admin')->only(['store','show','update']);
    }
    public function store(StoreListingRequest $request): JsonResponse
    {
        try {
            $listing = $this->listingService->createListing(
                $request->validated(),
                $request->user()->agent
            );

            return response()->json([
                'message' => 'Listing created successfully',
                 'data' => $listing->toArray(),
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create listing',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }
    public function show(Listing $listing): JsonResponse
    {
        // Load relations needed to resolve address hierarchy + AI context
        // (category, project, amenities — the audit modal's AI prompts use
        // these for richer title/description generation).
        $listing->load([
            'property.propertyAttribute.subtype.type',
            'property.furnishing',
            'property.barangay.city.province',
            'property.nearbyFacility',
            'property.project',
            'category',
            'agent.user',
        ]);

        // Transform response so property.address_id contains barangay + city + province info
        $payload = $listing->toArray();

        try {
            $barangay = optional($listing->property)->barangay;
            if ($barangay) {
                $city = optional($barangay)->city;
                $province = optional($city)->province;

                $payload['property']['address_id'] = [
                    'id'   => $barangay->id,
                    'name' => $barangay->name,
                    'city' => $city ? [
                        'id'         => $city->id,
                        'name'       => $city->name,
                        'postalcode' => $city->postalcode,
                    ] : null,
                    'province' => $province ? [
                        'id'   => $province->id,
                        'name' => $province->name,
                        'code' => $province->code,
                    ] : null,
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort; ignore transformation errors
        }

        return response()->json([
            'data' => $payload,
        ]);
    }
    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        try {
            $user  = $request->user();
            $agent = $user->agent;

            if ($user->role->name === 'agent' && $listing->agent_id !== $agent?->id) {
                return response()->json(['message' => 'You do not own this listing.'], 403);
            }

            $payload = $request->validated();

            // Only admins may update ATS status; reject agents attempting to change it
            if (array_key_exists('ats_status', $payload) && ($user->role->name ?? null) !== 'admin') {
                return response()->json([
                    'message' => 'Only admins can update ATS status.'
                ], 403);
            }

            // Capture snapshot before update if agent is editing a flagged listing
            $isFlaggedAgentEdit = $user->role->name === 'agent'
                && $listing->verification_status === 'flagged';

            $snapName          = $listing->name;
            $snapPrice         = (string) $listing->price;
            $snapDescription   = '';
            $snapAddress       = '';
            $snapBeds          = null;
            $snapBaths         = null;
            $snapGarage        = null;
            $snapLotArea       = null;
            $snapFloorArea     = null;
            $snapFeaturedCount = 0;
            $snapPhotosCount   = 0;

            if ($isFlaggedAgentEdit) {
                $listing->load('property.propertyAttribute');
                $snapDescription   = (string) ($listing->property->description ?? '');
                $snapAddress       = (string) ($listing->property->address ?? '');
                $snapBeds          = $listing->property->propertyAttribute->bedroom_count ?? null;
                $snapBaths         = $listing->property->propertyAttribute->bathroom_count ?? null;
                $snapGarage        = $listing->property->propertyAttribute->garage_count ?? null;
                $snapLotArea       = $listing->property->propertyAttribute->lot_area ?? null;
                $snapFloorArea     = $listing->property->propertyAttribute->floor_area ?? null;
                $snapFeaturedCount = count((array) ($listing->featured_photo ?? []));
                $snapPhotosCount   = count((array) ($listing->property->photos ?? []));
            }

            $updated = $this->listingService->updateListing(
                $payload,
                $listing,
                $agent
            );

            // Check the flag we attached in the Service
            if (!$updated->was_actually_updated) {
                return response()->json([
                    'message' => 'No changes detected. Listing is already up to date.',
                    'data'    => $updated->toArray(),
                ], 200);
            }

            // If agent edited a flagged listing, compute diff and set pending_review
            if ($isFlaggedAgentEdit) {
                $updated->load('property.propertyAttribute');
                $agentEdited = [];

                $cmp = fn($a, $b) => trim((string) $a) !== trim((string) $b);

                if ($cmp($snapName, $updated->name)) {
                    $agentEdited[] = ['label' => 'Title', 'original' => $snapName, 'current' => $updated->name];
                }
                if ($cmp($snapPrice, $updated->price)) {
                    $agentEdited[] = ['label' => 'Price', 'original' => $snapPrice, 'current' => (string) $updated->price];
                }
                $newDesc = (string) ($updated->property->description ?? '');
                if ($cmp($snapDescription, $newDesc)) {
                    $agentEdited[] = ['label' => 'Description', 'original' => $snapDescription, 'current' => $newDesc];
                }
                $newAddr = (string) ($updated->property->address ?? '');
                if ($cmp($snapAddress, $newAddr)) {
                    $agentEdited[] = ['label' => 'Address', 'original' => $snapAddress, 'current' => $newAddr];
                }
                $newBeds = $updated->property->propertyAttribute->bedroom_count ?? null;
                if ($snapBeds !== $newBeds) {
                    $agentEdited[] = ['label' => 'Bedrooms', 'original' => (string) $snapBeds, 'current' => (string) $newBeds];
                }
                $newBaths = $updated->property->propertyAttribute->bathroom_count ?? null;
                if ($snapBaths !== $newBaths) {
                    $agentEdited[] = ['label' => 'Bathrooms', 'original' => (string) $snapBaths, 'current' => (string) $newBaths];
                }
                $newGarage = $updated->property->propertyAttribute->garage_count ?? null;
                if ($snapGarage !== $newGarage) {
                    $agentEdited[] = ['label' => 'Garage', 'original' => (string) $snapGarage, 'current' => (string) $newGarage];
                }
                $newLot = $updated->property->propertyAttribute->lot_area ?? null;
                if ($snapLotArea !== $newLot) {
                    $agentEdited[] = ['label' => 'Lot Area', 'original' => (string) $snapLotArea, 'current' => (string) $newLot];
                }
                $newFloor = $updated->property->propertyAttribute->floor_area ?? null;
                if ($snapFloorArea !== $newFloor) {
                    $agentEdited[] = ['label' => 'Floor Area', 'original' => (string) $snapFloorArea, 'current' => (string) $newFloor];
                }
                $newFeaturedCount = count((array) ($updated->featured_photo ?? []));
                if ($snapFeaturedCount !== $newFeaturedCount) {
                    $agentEdited[] = ['label' => 'Featured Photo', 'original' => "{$snapFeaturedCount} photo(s)", 'current' => "{$newFeaturedCount} photo(s)"];
                }
                $newPhotosCount = count((array) ($updated->property->photos ?? []));
                if ($snapPhotosCount !== $newPhotosCount) {
                    $agentEdited[] = ['label' => 'Gallery Photos', 'original' => "{$snapPhotosCount} photo(s)", 'current' => "{$newPhotosCount} photo(s)"];
                }

                $updated->updateQuietly([
                    'verification_status' => 'pending_review',
                    'agent_edited_fields' => $agentEdited,
                    're_submitted_at'     => now(),
                ]);
                $updated->refresh();
            }

            return response()->json([
                'message' => 'Listing updated successfully',
                'data'    => $updated->toArray(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update listing',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred',
            ], 500);
        }
    }
}