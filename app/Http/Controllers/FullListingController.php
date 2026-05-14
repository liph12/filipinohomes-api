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
        // Load relations needed to resolve address hierarchy
        $listing->load([
            'property.propertyAttribute.subtype.type',
            'property.furnishing',
            'property.barangay.city.province',
            'property.nearbyFacility',
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