<?php

namespace App\Http\Controllers;

use App\Services\Location\LocationGeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /locations/geo?barangay_id=… | city_id=… | province_id=…
 *
 * Where a picked place is (centre + box) from our own boundary shapes, so the location pickers
 * don't have to geocode it. 200 { found: true, level, lat, lng, bounds } or
 * 200 { found: false } when there's no shape — the frontend then geocodes the name instead.
 * Only one ID is used, the most specific given (barangay → city → province).
 */
class LocationGeoController extends Controller
{
    public function __invoke(Request $request, LocationGeoService $geo): JsonResponse
    {
        $v = $request->validate([
            'barangay_id' => 'nullable|integer|min:1',
            'city_id' => 'nullable|integer|min:1',
            'province_id' => 'nullable|integer|min:1',
        ]);

        [$level, $hit] = match (true) {
            isset($v['barangay_id']) => ['barangay', $geo->barangay((int) $v['barangay_id'])],
            isset($v['city_id']) => ['city', $geo->city((int) $v['city_id'])],
            isset($v['province_id']) => ['province', $geo->province((int) $v['province_id'])],
            default => [null, null],
        };

        if ($level === null) {
            return response()->json(['message' => 'Give a barangay_id, city_id or province_id.'], 422);
        }

        return response()->json($hit ? ['found' => true, 'level' => $level] + $hit : ['found' => false, 'level' => $level]);
    }
}
