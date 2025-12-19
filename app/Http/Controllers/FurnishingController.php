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
        return new FurnishingResourceCollection(
            Furnishing::get()
        );
    }

    public function show($id)
    {
        return new FurnishingResource(
            Furnishing::find($id)
        );
    }

    public function store(Request $request)
    {
        return new FurnishingResource(
            Furnishing::create($request->only([
                'name',
                'status'
            ]))
        );
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
        Furnishing::findOrFail($id)->delete();
    }
}
