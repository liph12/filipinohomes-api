<?php
namespace App\Http\Controllers;
use App\Http\Resources\CategoryResourceCollection;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
class CategoryController extends Controller
{
    public function index()
    {
        return new CategoryResourceCollection(
           Category::get()
        );
    }

    public function show($id)
    {
        return new CategoryResource(
            Category::find($id)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id'     => 'sometimes|integer|exists:categories,id',
            'name'   => 'required|string|max:255',
            'status' => 'required|string|max:255',
        ]);

        return new CategoryResource(
            Category::updateOrCreate(
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
        Category::findOrFail($id)->delete();
    }
}
