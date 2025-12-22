<?php
namespace App\Http\Controllers;
use App\Http\Resources\PropertyTypeResourceCollection;
use App\Http\Resources\PropertyTypeResource;
use App\Models\PropertyType;
use Illuminate\Http\Request;

class PropertyTypeController extends Controller
{
     public function index()
    {
        return new PropertyTypeResourceCollection(
           PropertyType::get()
        );
    }

    public function show($id)
    {
        return new PropertyTypeResource(
            PropertyType::find($id)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id'     => 'sometimes|integer|exists:property_types,id',
            'name'   => 'required|string|max:255',
            'status' => 'required|string|max:255',
        ]);

        return new PropertyTypeResource(
            PropertyType::updateOrCreate(
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
        PropertyType::findOrFail($id)->delete();
    }
}
