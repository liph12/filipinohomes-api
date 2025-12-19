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
        return new CategoryResource(
            Category::create($request->only([
                'name',
                'status'
            ]))
        );
    }

    public function update($id, Request $request)
    {
        $category = Category::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|max:255'
        ]);
        $category->update($validated);
        return new CategoryResource($category);
    }

    public function destroy($id)
    {
        Category::findOrFail($id)->delete();
    }
}
