<?php

namespace App\Http\Controllers;
 
use App\Models\Province;
use Illuminate\Http\JsonResponse;
 
class ProvinceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Province::orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }
 
    public function cities(Province $province): JsonResponse
    {
        return response()->json([
            'data' => $province->cities()
                ->orderBy('name')
                ->get(['id', 'name', 'province_id', 'type', 'postalcode']),
        ]);
    }
}
