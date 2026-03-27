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

    public function update(Request $request, Office $id)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|unique:offices,slug,' . $id,
            'title' => 'nullable|string|max:255',
            'contact' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'photo' => 'nullable|array',
            'photo.*' => 'nullable|string',
            'geo_coordinates' => 'nullable|array:lat,lng',
        ]);

        $id->update($data);

        return response()->json(new OfficeResource($id));
    }

    public function destroy(Office $office)
    {
        $office->delete();

        return response()->json(null, 204);
    }
}