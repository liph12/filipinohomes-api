<?php
namespace App\Http\Controllers;
use App\Http\Resources\FurnishingResourceCollection;
use App\Http\Resources\FurnishingResource;
use App\Models\Furnishing;
use Illuminate\Http\Request;
class FurnishingController extends Controller
{
    public function index()
    {
        $furnishings = Furnishing::get();
        return new FurnishingResourceCollection($furnishings);
    }

    public function show($id)
    {
        $furnishings = Furnishing::find($id);
        return new FurnishingResource($furnishings);
    }

    public function store(Request $request)
    {
        $furnishings = Furnishing::create($request->only([
            'name',
            'status'
        ]));
        return new FurnishingResource($furnishings);
    }

    public function update($id, Request $request)
    {
        $furnishings = Furnishing::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|max:255'
        ]);
        $furnishings->update($validated);
        return new FurnishingResource($furnishings);
    }

    public function destroy($id)
    {
        $furnishings = Furnishing::findOrFail($id);
        $furnishings->delete();
        return response()->json([
            'message' => 'Furnishing deleted successfully',
            'id' => $id
        ], 200);
    }
}
