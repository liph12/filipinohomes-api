<?php
namespace App\Http\Controllers;
use App\Http\Resources\PropertySubtypeResourceCollection;
use App\Http\Resources\PropertySubtypeResource;
use App\Models\PropertySubtype;
use Illuminate\Http\Request;

class PropertySubtypeController extends Controller
{
     public function index()
    {
        return new PropertySubtypeResourceCollection(
           PropertySubtype::get()
        );
    }

    public function show($id)
    {
        return new PropertySubtypeResource(
            PropertySubtype::find($id)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id'              => 'sometimes|integer|exists:property_subtypes,id',
            'name'            => 'required|string|max:255',
            'status'          => 'required|string|max:255',
            'property_type_id'=> 'required|integer|exists:property_types,id',
        ]);

        return new PropertySubtypeResource(
            PropertySubtype::updateOrCreate(
                ['id' => $validated['id'] ?? null],
                [
                    'name'              => $validated['name'],
                    'status'            => $validated['status'],
                    'property_type_id'  => $validated['property_type_id'],
                ]
            )
        );
    }
    
    public function destroy($id)
    {
        PropertySubtype::findOrFail($id)->delete();
    }
}
