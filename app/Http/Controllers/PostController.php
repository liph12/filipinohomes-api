<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $sort = $request->query('sort');

        $query = Post::with('category')
            ->whereNotNull('published_at');

        if ($sort === 'views') {
            $query->orderByDesc('views')
                ->orderByDesc('published_at');
        } else {
            $query->orderByDesc('published_at');
        }

        return response()->json(
            $query->paginate(10)
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
