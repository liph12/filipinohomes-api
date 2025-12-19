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
        $validated = $request->validate([
            'id'     => 'sometimes|integer|exists:furnishings,id',
            'name'   => 'required|string|max:255',
            'status' => 'required|string|max:255',
        ]);

        return new FurnishingResource(
            Furnishing::updateOrCreate(
                ['id' => $validated['id'] ?? null],
                [
                    'name'   => $validated['name'],
                    'status' => $validated['status'],
                ]
            )
        );
    }


    public function destroy($id)
    {
        Furnishing::findOrFail($id)->delete();
    }
}
