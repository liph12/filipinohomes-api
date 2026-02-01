<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Resources\AmenityResourceCollection;
use App\Http\Resources\AmenityResource;
use App\Models\Amenity;

class AmenityController extends Controller
{
    public function index()
    {
        return new AmenityResourceCollection(
           Amenity::get()
        );
    }

    public function show($id)
    {
        return new AmenityResource(
            Amenity::find($id)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id'     => 'sometimes|integer|exists:property_types,id',
            'name'   => 'required|string|max:255',
            'slug' => 'required|string|max:255',
        ]);

        return new AmenityResource(
            Amenity::updateOrCreate(
                ['id' => $validated['id'] ?? null],
                [
                    'name'   => $validated['name'],
                    'slug' => $validated['slug'],
                ]
            )
        );
    }
    
    public function destroy($id)
    {
        Amenity::findOrFail($id)->delete();
    }
}
