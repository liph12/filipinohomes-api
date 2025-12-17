<?php

namespace App\Http\Controllers;

use App\Http\Resources\PropertyAttributesResourceCollection;
use App\Http\Resources\PropertyAttributesResource;
use App\Models\PropertyAttribute;
use Illuminate\Http\Request;
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'bedroom_count'   => 'required|integer|min:0',
            'bathroom_count'  => 'required|integer|min:0',
            'garage_count'    => 'required|integer|min:0',
            'lot_area'        => 'required|numeric',
            'floor_area'      => 'required|numeric',
        ]);

        $propertyAttributes = PropertyAttribute::updateOrCreate(
            ['id' => $request->id], // MUST be provided
            $validated
        );

        return new PropertyAttributesResource($propertyAttributes);
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
