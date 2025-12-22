<?php
namespace App\Http\Controllers;
use App\Http\Resources\ListingInquiryResourceCollection;
use App\Http\Resources\ListingInquiryResource;
use App\Models\ListingInquiry;
use Illuminate\Http\Request;
class ListingInquiryController extends Controller
{
    public function index()
    {
        return new ListingInquiryResourceCollection(
            ListingInquiry::get()
        );
    }

    public function show($id)
    {
        return new ListingInquiryResource(
            ListingInquiry::find($id)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'status'         => 'sometimes|string|max:255',
            'client_id'      => 'required|integer|exists:users,id',
            'listing_id'     => 'required|integer|exists:listings,id',
            'conversation_id'=> 'required|integer|exists:listing_conversations,id',
        ]);

        $listingConversation = ListingInquiry::updateOrCreate(
            ['id' => $request->id], // MUST be provided for update
            $validated
        );
        return new ListingInquiryResource($listingConversation);
    }

    public function destroy($id)
    {
        ListingInquiry::findOrFail($id)->delete();
    }
}
