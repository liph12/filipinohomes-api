<?php

namespace App\Http\Controllers;
 
use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
 
class CityController extends Controller
{
    /**
     * Cities and municipalities whose name starts with (then contains) the
     * typed text, with their province, for the Buyers & Sellers Link brief's
     * area search. At most ten, prefix matches first.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }
        $like = str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 60));

        $rows = City::query()
            ->with('province:id,name')
            ->where('name', 'like', "%{$like}%")
            ->orderByRaw('(name like ?) desc', ["{$like}%"])
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'province_id']);

        return response()->json([
            'data' => $rows->map(fn (City $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'province' => $c->province?->name,
                'label' => $c->province ? "{$c->name}, {$c->province->name}" : $c->name,
            ])->values(),
        ]);
    }

    public function barangays(City $city): JsonResponse
    {
        return response()->json([
            'data' => $city->barangays()
                ->orderBy('name')
                ->get(['id', 'name', 'city_id']),
        ]);
    }
}
 