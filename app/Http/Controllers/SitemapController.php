<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Listing;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ->select('id', 'slug', 'created_at', 'updated_at')
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
            ->select('id', 'first_name', 'middle_name', 'last_name', 'created_at', 'updated_at')
            ->has('listings')
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
            ->select('id', 'slug', 'published_at', 'updated_at')
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
            ->select('id', 'slug', 'name', 'featured_photo', 'property_id', 'updated_at')
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
            ->select('id', 'slug', 'title', 'featured_image', 'updated_at')
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
     * Listing counts grouped by city, province, category, and property type.
     * Used by the frontend sitemap to only include location URLs that have data.
     */
    public function locationCounts(): JsonResponse
    {
        $rows = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('barangays', 'barangays.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', 'barangays.city_id')
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
            ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
            ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
            ->where('listings.visibility', 'public')
            ->where('properties.status', 'active')
            ->select(
                'cities.name as city',
                'provinces.name as province',
                'categories.name as category',
                'property_types.name as type',
                'property_subtypes.name as subtype',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('cities.name', 'provinces.name', 'categories.name', 'property_types.name', 'property_subtypes.name')
            ->having    ('total', '>=', 1)
            ->get();

        return response()->json($rows);
    }

    /**
     * Agent images for image-sitemap.xml
     */
    public function agentImages(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 500), 1000);

        $paginator = Agent::query()
            ->select('id', 'first_name', 'middle_name', 'last_name', 'avatar', 'updated_at')
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
