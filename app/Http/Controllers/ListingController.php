<?php
namespace App\Http\Controllers;
use App\Http\Resources\ListingResourceCollection;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;
class ListingController extends Controller
{
    public function index()
    {
        $listings = Listing::get();
        return new ListingResourceCollection($listings);
    }

    public function show($id)
    {
        $listings = Listing::find($id);
        return new ListingResource($listings);
    }
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'           => 'required|string|max:255',
            'status'         => 'required|string|max:255',
            'name'           => 'required|string|max:255',
            'slug'           => 'nullable|string|max:255',
            'price'          => 'required|numeric|min:0',
            'featured_photo' => 'nullable|string|max:255',
            'is_featured'    => 'sometimes|boolean',
            'clicks'         => 'nullable|integer|min:0',
            'property_id'    => 'nullable|integer|exists:properties,id',
            'category_id'    => 'required|integer|exists:categories,id',
            'agent_id'       => 'required|integer|exists:agents,id',
        ]);
        $listings = Listing::updateOrCreate(
            ['id' => $request->id], // MUST be provided
            $validated
        );
        return new ListingResource($listings);
    }

    public function destroy($id)
    {
        $listings = Listing::findOrFail($id);
        $listings->delete();
        return response()->json([
            'message' => 'Listing deleted successfully',
            'id' => $id
        ], 200);
    }
}
