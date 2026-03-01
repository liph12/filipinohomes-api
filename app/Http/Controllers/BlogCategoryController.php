<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;

class BlogCategoryController extends Controller
{
    public function index()
    {
        return response()->json(
            BlogCategory::withCount('posts')
                ->having('posts_count', '>', 0)    
                ->where('slug', '!=', 'uncategorized') 
                ->orderBy('posts_count', 'desc')     
                ->get()
        );
    }

public function show($slug)
{
    $category = BlogCategory::where('slug', $slug)->firstOrFail();

    $posts = $category->posts()
        ->whereNotNull('published_at')
        ->orderByDesc('published_at')
        ->paginate(10);

    return response()->json([
        'category' => $category,
        'posts' => $posts
    ]);
}
}