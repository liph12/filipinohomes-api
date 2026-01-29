<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreListingRequest;
use App\Services\Listing\ListingService;
use Illuminate\Http\JsonResponse;

class FullListingController extends Controller
{
    public function __construct(
        protected ListingService $listingService
    ) {}

    public function store(StoreListingRequest $request): JsonResponse
    {
        try {
            $listing = $this->listingService->createListing(
                $request->validated(),
                $request->user()->agent
            );

            return response()->json([
                'message' => 'Listing created successfully',
                'data' => $listing,
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
}