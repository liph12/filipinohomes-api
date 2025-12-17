<?php

namespace App\Http\Controllers;
use App\Http\Resources\PropertyAttributesResourceCollection;
use App\Http\Resources\PropertyAttributesResource;
use App\Models\PropertyAttribute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PropertyAttributesController extends Controller
{
    public function index()
    {
        $property_attributes = PropertyAttribute::get();

        return new PropertyAttributesResourceCollection($property_attributes);
    }

    public function show($id)
    {
        $property_attributes = PropertyAttribute::find($id);

        return new PropertyAttributesResource($property_attributes);
    }
    // uncomment if want to use updateOrCreate
    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'bedroom_count'   => 'required|integer|min:0',
    //         'bathroom_count'  => 'required|integer|min:0',
    //         'garage_count'    => 'nullable|integer|min:0',
    //         'lot_area'        => 'nullable|numeric',
    //         'floor_area'      => 'nullable|numeric',
    //     ]);

    //     $propertyAttributes = PropertyAttribute::updateOrCreate(
    //         ['id' => $request->id], // MUST be provided
    //         $validated
    //     );

    //     return new PropertyAttributesResource($propertyAttributes);
    // }

    public function store(Request $request)
    {
        $property_attributes = PropertyAttribute::create($request->only([
            'bedroom_count',
            'bathroom_count',
            'garage_count',
            'lot_area',
            'floor_area'
        ]));

        return new PropertyAttributesResource($property_attributes);
    }

    public function update(Request $request ,$id)
    {
        $property_attributes = PropertyAttribute::findOrFail($id);

        $validated = $request->validate([
            'bedroom_count' => 'sometimes|integer|min:0',
            'bathroom_count' => 'sometimes|integer|min:0',
            'garage_count' => 'sometimes|integer|min:0',
            'lot_area' => 'sometimes|numeric',
            'floor_area' => 'sometimes|numeric'
        ]);

        $property_attributes->update($validated);

        return new PropertyAttributesResource($property_attributes);
    }

    public function destroy($id)
    {
        // Find the user or fail with 404
        $property_attributes = PropertyAttribute::findOrFail($id);

        $property_attributes->delete();
        return response()->json([
            'message' => 'PropertyAttributes deleted successfully',
            'id' => $id
        ], 200);
    }
}
