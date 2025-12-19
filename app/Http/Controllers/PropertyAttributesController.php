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
        return new PropertyAttributesResourceCollection(
            PropertyAttribute::get()
        );
    }

    public function show($id)
    {
        return new PropertyAttributesResource(
            PropertyAttribute::find($id)
        );
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
        PropertyAttribute::findOrFail($id)->delete();
    }
}
