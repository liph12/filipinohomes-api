<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Listing;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    /**
     * Lightweight listing data for sitemap.xml
     */
    public function listings(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Listing::query()
            ->where('visibility', 'public')
            ->select('id', 'slug', 'created_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Lightweight agent data for sitemap.xml
     */
    public function agents(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Agent::query()
            ->select('id', 'created_at') 
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Lightweight blog data for sitemap.xml
     */
    public function blogs(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Post::query()
            ->whereNotNull('published_at')
            ->select('id', 'slug', 'published_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Listing images for image-sitemap.xml
     */
    public function listingImages(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 200), 500);

        $paginator = Listing::query()
            ->where('visibility', 'public')
            ->select('id', 'slug', 'name', 'featured_photo', 'property_id')
            ->with('property:id,photos')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Blog images for image-sitemap.xml
     */
    public function blogImages(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Post::query()
            ->whereNotNull('published_at')
            ->select('id', 'slug', 'title', 'featured_image')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Agent images for image-sitemap.xml
     */
    public function agentImages(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Agent::query()
            ->select('id', 'first_name', 'middle_name', 'last_name', 'avatar')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }
}
