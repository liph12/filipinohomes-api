<?php

namespace App\Http\Controllers;

use App\Http\Resources\OfficeResource;
use App\Models\Office;
use Illuminate\Http\Request;

class OfficeController extends Controller
{
    public function index()
    {
        return response()->json(
            Office::latest()->get()
        );
    }

    public function show(string $slug)
    {
        $office = Office::where('slug', $slug)->firstOrFail();

        return new OfficeResource($office);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:offices,slug',
            'title' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'photo' => 'nullable|array',
            'photo.*' => 'nullable|string',
            'geo_coordinates' => 'nullable|array:lat,lng',
        ]);

        $office = Office::create($data);

        return response()->json(new OfficeResource($office), 201);
    }

    public function update(Request $request, Office $office)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|unique:offices,slug,' . $office->id,
            'title' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'photo' => 'nullable|array',
            'photo.*' => 'nullable|string',
            'geo_coordinates' => 'nullable|array:lat,lng',
        ]);

        $office->update($data);

        return response()->json(new OfficeResource($office));
    }

    public function destroy($office)
    {
        $model = Office::find($office);
        if (! $model) {
            return response()->json([
                'message' => 'Office not found',
                'id' => is_numeric($office) ? (int) $office : $office,
            ], 404);
        }

        $model->delete();

        return response()->json([
            'message' => 'Office deleted',
            'id' => $model->id,
        ], 200);
    }
}