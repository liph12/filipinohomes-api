<?php

namespace App\Http\Controllers;

use App\Http\Resources\ListingConversationResourceCollection;
use App\Http\Resources\ListingConversationResource;
use App\Models\ListingConversation;
use Illuminate\Http\Request;

class ListingConversationController extends Controller
{
    public function index()
    {
        $listing_Conversations = ListingConversation::get();

        return new ListingConversationResourceCollection($listing_Conversations);
    }

    public function show($id)
    {
        $listing_Conversations = ListingConversation::find($id);

        return new ListingConversationResource($listing_Conversations);
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'messages'       => 'sometimes|string|max:255',
            'client_status'  => 'sometimes|string|max:255',
            'agent_status'   => 'sometimes|string|max:255',
        ]);

        $listingConversation = ListingConversation::updateOrCreate(
            ['id' => $request->id], // MUST be provided for update
            $validated
        );

        return new ListingConversationResource($listingConversation);
    }

    public function destroy($id)
    {
        // Find the user or fail with 404
        $listing_Conversations = ListingConversation::findOrFail($id);

        $listing_Conversations->delete();
        return response()->json([
            'message' => 'Listing Conversation deleted successfully',
            'id' => $id
        ], 200);
    }
}
