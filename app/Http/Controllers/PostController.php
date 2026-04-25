<?php

namespace App\Http\Controllers;

use App\Models\Post;

class PostController extends Controller
{
    public function index()
    {
        return response()->json(
            Post::with('category')
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->paginate(10)  
        );
    }

    public function show($slug)
    {
        $post = Post::with('category')
            ->where('slug', $slug)
            ->firstOrFail();

        $post->increment('views');

        return response()->json($post);
    }
}