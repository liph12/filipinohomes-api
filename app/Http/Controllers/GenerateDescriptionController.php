<?php

namespace App\Http\Controllers;

use App\Services\OpenAI\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateDescriptionController extends Controller
{
    public function __construct(protected CacheService $cacheService) {}

    public function generate(Request $request, string $name): JsonResponse
    {
        if (! Gate::allows('bypass-ai-daily-limit')) {
            $limitResponse = $this->cacheService->updateDailyLimit($request, 'create_text');
            if ($limitResponse->getStatusCode() !== 200) {
                return $limitResponse;
            }
        }

        $response = Http::get(
            'https://api.leuteriorealty.com/fh/v2/public/api/generate-description-tags/' . urlencode($name)
        );

        if ($response->failed()) {
            Log::warning('Generate description API error', ['status' => $response->status()]);
            return response()->json(['message' => 'Failed to generate description.'], 502);
        }

        $data = $response->json();

        return response()->json([
            'description' => $data['description'] ?? $data['data']['description'] ?? '',
            'tags'        => $data['tags']        ?? $data['data']['tags']        ?? [],
        ]);
    }
}
