<?php

namespace App\Http\Controllers\FhOne;

use App\Http\Controllers\Controller;
use App\Services\FhOne\LookupService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/fh-one/v1/lookup/{type}?q= — a name to the id a filter needs. Behind VerifyFhOneKey. */
class LookupController extends Controller
{
    public function index(Request $request, LookupService $lookup, string $type): JsonResponse
    {
        if (! isset(LookupService::TYPES[$type])) {
            throw new HttpResponseException(response()->json([
                'error' => 'unknown_lookup',
                'message' => "There is no lookup '{$type}'. Use one of: ".implode(', ', array_keys(LookupService::TYPES)).'.',
            ], 404));
        }

        $data = $request->validate(['q' => ['nullable', 'string', 'max:60']]);
        $result = $lookup->search($type, (string) ($data['q'] ?? ''));

        return response()->json([
            'type' => $type,
            'filter' => LookupService::TYPES[$type],
            'q' => (string) ($data['q'] ?? ''),
            'data' => $result['data'],
            'meta' => [
                'limit' => LookupService::LIMIT,
                'truncated' => $result['truncated'],
                'min_chars' => in_array($type, ['projects', 'cities'], true) ? LookupService::MIN_CHARS : 0,
            ],
        ]);
    }
}
