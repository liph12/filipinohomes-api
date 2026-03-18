<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateDescriptionController extends Controller
{
    public function generate(string $name): JsonResponse
    {
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