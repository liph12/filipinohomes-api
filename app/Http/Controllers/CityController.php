<?php

namespace App\Http\Controllers;
 
use App\Models\City;
use Illuminate\Http\JsonResponse;
 
class CityController extends Controller
{
    public function barangays(City $city): JsonResponse
    {
        return response()->json([
            'data' => $city->barangays()
                ->orderBy('name')
                ->get(['id', 'name', 'city_id']),
        ]);
    }
}
 