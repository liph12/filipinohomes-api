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
        $categories = Category::get();
        return new CategoryResourceCollection($categories);
    }

    public function show($id)
    {
        $category = Category::find($id);
        return new CategoryResource($category);
    }

    public function store(Request $request)
    {
        $category = Category::create($request->only([
            'name',
            'status'
        ]));
        return new CategoryResource($category);
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
        $category = Category::findOrFail($id);
        $category->delete();
        return response()->json([
            'message' => 'Category deleted successfully',
            'id' => $id
        ], 200);
    }
}
