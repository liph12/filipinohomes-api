<?php
namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Property;
use App\Models\PropertyAttribute;
use App\Models\PropertySubtype;
use App\Models\PropertyType;
use App\Models\Agent;
use App\Models\Category;
use App\Models\Furnishing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class FullListingController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        $agent = Agent::where('user_id', $user->id)->first();
        if (!$agent) {
            return response()->json([
                'message' => 'Logged in user is not an agent'
            ], 403);
        }

        $agentId = $agent->id;

        // Convert single string to array if needed
        $photos = is_array($request->photos) ? $request->photos : ($request->photos ? [$request->photos] : []);
        $amenities = is_array($request->amenities) ? $request->amenities : ($request->amenities ? [$request->amenities] : []);

        // Find property type and subtype
        $propertyType = PropertyType::find($request->property_type_id);
        if (!$propertyType) {
            return response()->json(['message' => 'Property type not found'], 404);
        }

        $propertySubtype = PropertySubtype::find($request->property_subtype_id);
        if (!$propertySubtype) {
            return response()->json(['message' => 'Property subtype not found'], 404);
        }

        if ($propertySubtype->property_type_id !== $propertyType->id) {
            return response()->json(['message' => 'Property subtype does not belong to the selected property type'], 400);
        }

        // Create property attributes
        $propertyAttribute = PropertyAttribute::create([
            'bedroom_count' => $request->bedroom_count,
            'bathroom_count' => $request->bathroom_count,
            'garage_count' => $request->garage_count,
            'lot_area' => $request->lot_area,
            'floor_area' => $request->floor_area,
            'property_subtype_id' => $propertySubtype->id,
        ]);

        // Auto-create furnishing if name provided
        $furnishing = null;
        if ($request->furnishing_name) {
            $furnishing = Furnishing::firstOrCreate(
                ['name' => $request->furnishing_name], // search by name
                ['status' => 'active']                 // default status
            );
        }

        // Create property
        $property = Property::create([
            'name' => $request->name,
            'address' => $request->address,
            'photos' => $photos,
            'amenities' => $amenities,
            'description' => $request->description,
            'geo_coordinates' => json_encode($request->geo_coordinates),
            'is_project' => $request->is_project ?? false,
            'property_attribute_id' => $propertyAttribute->id,
            'furnishing_id' => $furnishing->id ?? null, // use created furnishing if any
        ]);

        // Auto-create category if name provided
        $category = null;
        if ($request->category_name) {
            $category = Category::firstOrCreate(
                ['name' => $request->category_name],
                ['status' => 'active']
            );
        }

        // Create listing
        $listing = Listing::create([
            'code' => $request->code,
            'status' => $request->status ?? 'active',
            'name' => $request->name,
            'slug' => $request->slug ?? Str::slug($request->name),
            'price' => $request->price,
            'featured_photo' => json_encode($request->featured_photo ?? ($photos[0] ?? null)),
            'is_featured' => $request->is_featured ?? false,
            'clicks' => $request->clicks ?? 0,
            'property_id' => $property->id,
            'category_id' => $category->id ?? null, // use created category if any
            'agent_id' => $agentId,
        ]);

        return response()->json([
            'message' => 'Listing created successfully!',
            'listing' => $listing,
        ], 201);
    }
}
