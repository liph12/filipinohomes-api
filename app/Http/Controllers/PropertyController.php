<?php
namespace App\Http\Controllers;
use App\Http\Resources\PropertyResourceCollection;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use Illuminate\Http\Request;
class PropertyController extends Controller
{
    public function index()
    {
        return new PropertyResourceCollection(
            Property::get()
        );
    }

    public function show($id)
    {
        return new PropertyResource(
            Property::find($id)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'address'               => 'nullable|string|max:255',
            'photos'                => 'nullable|array',
            'photos.*'              => 'string', // each photo filename or URL
            'amenities'             => 'nullable|array',
            'amenities.*'           => 'string', // each amenity
            'description'           => 'nullable|string',
            'geo_coordinates'       => 'nullable|string', // or regex for lat,lng
            'is_project'            => 'sometimes|boolean',
            'property_attribute_id' => 'required|integer|exists:property_attributes,id',
            'furnishing_id'         => 'required|integer|exists:furnishings,id',
        ]);

        $properties = Property::updateOrCreate(
            ['id' => $request->id], // MUST be provided
            $validated
        );
        $message = $properties->wasRecentlyCreated
            ? 'Property has been created successfully.'
            : 'Property has been updated successfully.';

        return response()->json([
            'message'  => $message,
            'property' => new PropertyResource($properties)
        ]);
    }

    public function destroy($id)
    {
        Property::findOrFail($id)->delete();
    }
}
