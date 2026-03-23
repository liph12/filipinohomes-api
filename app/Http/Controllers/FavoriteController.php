<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Favorite;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\ListingResourceCollection;
class FavoriteController extends Controller
{
    public function toggle($listingId)
    {
        $favorite = Favorite::firstOrCreate([
            'user_id'    => Auth::id(),
            'listing_id' => $listingId,
        ]);

        if (!$favorite->wasRecentlyCreated) {
            $favorite->delete();
            return response()->json(['favorited' => false]);
        }

        return response()->json(['favorited' => true]);
    }

    public function sync(Request $request)
    {
        $request->validate([
            'listing_ids'   => 'required|array',
            'listing_ids.*' => 'integer|exists:listings,id',
        ]);

        $userId = Auth::id();

        foreach ($request->listing_ids as $listingId) {
            Favorite::firstOrCreate([
                'user_id'    => $userId,
                'listing_id' => $listingId,
            ]);
        }

        return response()->json([
            'listing_ids' => Favorite::where('user_id', $userId)->pluck('listing_id'),
        ]);
    }

    public function index(Request $request)
    {
        $perPage = 10;
        $page    = $request->integer('page', 1);

        $favorites = Favorite::where('user_id', Auth::id())
            ->with([
                'listing' => function ($q) {
                    $q->with([
                        'property.propertyAttribute.subtype',
                        'category',
                        'agent' => function ($q) {
                            $q->withCount('listings');
                        }
                    ]);
                }
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $listings = $favorites->pluck('listing')->filter()->values();

        return new ListingResourceCollection(
            new \Illuminate\Pagination\LengthAwarePaginator(
                $listings,
                $favorites->total(),
                $perPage,
                $page,
                ['path' => $request->url()]
            )
        );
    }
}