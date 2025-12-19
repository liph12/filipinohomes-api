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
        return new ListingConversationResourceCollection(
            ListingConversation::get()
        );
    }

    public function show($id)
    {
        return new ListingConversationResource(
            ListingConversation::find($id)
        );
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
        ListingConversation::findOrFail($id)->delete();
    }
}
