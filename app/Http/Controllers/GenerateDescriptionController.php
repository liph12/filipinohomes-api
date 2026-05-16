<?php

namespace App\Http\Controllers;

use App\Services\OpenAI\CacheService;
use App\Services\OpenAI\ListingCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GenerateDescriptionController extends Controller
{
    public function __construct(
        protected CacheService $cacheService,
        protected ListingCommandService $listingService,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        $title = trim((string) $request->input('title', ''));
        if (strlen($title) < 3) {
            return response()->json(['message' => 'Title is too short.'], 422);
        }

        $limitResponse = $this->cacheService->updateDailyLimit($request, 'create_text');
        if ($limitResponse->getStatusCode() !== 200) {
            return $limitResponse;
        }

        // NOTE: `description` is intentionally NOT whitelisted here. Feeding the
        // model its previous output makes "Regenerate" tend to echo / lightly
        // paraphrase the existing copy. Each click should produce a fresh
        // description from the structured facts only. analyzeTitle still uses
        // `description` as context, that flow benefits from seeing it.
        $context = $request->only([
            'category', 'property_type', 'property_subtype',
            'project_name', 'project_location',
            'bedrooms', 'bathrooms', 'parking',
            'floor_area', 'lot_area',
            'price', 'furnishing',
            'amenities',
            'address', 'barangay', 'city', 'province',
            'nearby_facilities',
            'photo_keywords',
        ]);

        try {
            $result = $this->listingService->generateDescription($title, $context);

            if (!$result) {
                return response()->json(['message' => 'Failed to generate description.'], 502);
            }

            return response()->json([
                'description' => $result['description'] ?? '',
            ]);
        } catch (\OpenAI\Exceptions\RateLimitException $e) {
            return response()->json([
                'message' => 'AI service is temporarily busy. Please try again in a moment.',
            ], 429);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate description. Please try again.',
            ], 500);
        }
    }
}
