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
        $this->middleware(RoleMiddleware::class . ':agent,admin')->only(['store']);
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

    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        try {
            $user  = $request->user();
            $agent = $user->agent;

            if ($user->role('agent') && $listing->agent_id !== $agent?->id) {
                return response()->json(['message' => 'You do not own this listing.'], 403);
            }

            $updated = $this->listingService->updateListing(
                $request->validated(),
                $listing,
                $agent
            );

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