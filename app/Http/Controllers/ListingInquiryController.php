<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\ListingInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingInquiryController extends Controller
{
    /**
     * List all inquiries for the authenticated user.
     * - Agents see inquiries where they are the agent.
     * - Clients see inquiries they created.
     * - Admins see all.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ListingInquiry::with([
            'listing:id,name,slug,featured_photo,price',
            'client:id,name,email',
            'agent:id,first_name,last_name,mobile_no',
            'latestMessage',
        ]);

        $role = $user->role->name;

        if ($role === 'agent') {
            $query->where('agent_id', $user->agent->id);
        } elseif ($role !== 'admin') {
            // Regular client — only see their own inquiries
            $query->where('client_id', $user->id);
        }

        $inquiries = $query->latest()->paginate($request->integer('per_page', 15));

        return response()->json($inquiries);
    }

    /**
     * Create a new inquiry.
     * The agent is automatically resolved from the listing.
     * One inquiry per client per listing (enforced by DB unique constraint).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'listing_id' => ['required', 'integer', 'exists:listings,id'],
            'message'    => ['required', 'string', 'max:2000'],
        ]);

        $listing = Listing::findOrFail($request->listing_id);
        $user    = $request->user();

        // Reuse existing inquiry if already exists for this client+listing
        $inquiry = ListingInquiry::firstOrCreate(
            [
                'listing_id' => $listing->id,
                'client_id'  => $user->id,
            ],
            [
                'agent_id' => $listing->agent_id,
                'status'   => 'pending',
            ]
        );

        // Always add the opening message
        $inquiry->conversations()->create([
            'sender_id' => $user->id,
            'message'   => $request->message,
        ]);

        // Activate inquiry once first message is sent
        if ($inquiry->status === 'pending') {
            $inquiry->update(['status' => 'active']);
        }

        return response()->json([
            'message'    => 'Inquiry sent successfully.',
            'inquiry_id' => $inquiry->id,
        ], 201);
    }

    /**
     * Show a single inquiry with full conversation thread.
     */
    public function show(Request $request, ListingInquiry $listingInquiry): JsonResponse
    {
        $this->authorize('view', $listingInquiry);

        $listingInquiry->load([
            'listing:id,name,slug,featured_photo,price,agent_id',
            'client:id,name,email',
            'agent:id,first_name,last_name,mobile_no',
            'conversations.sender:id,name',
        ]);

        // Mark all messages from the other party as read
        $listingInquiry->conversations()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $request->user()->id)
            ->update(['read_at' => now()]);

        return response()->json(['data' => $listingInquiry]);
    }

    /**
     * Update inquiry status (agent/admin only).
     */
    public function update(Request $request, ListingInquiry $listingInquiry): JsonResponse
    {
        $this->authorize('update', $listingInquiry);

        $request->validate([
            'status' => ['required', 'in:pending,active,closed'],
        ]);

        $listingInquiry->update(['status' => $request->status]);

        return response()->json(['status' => $listingInquiry->status]);
    }

    /**
     * Delete an inquiry (admin only).
     */
    public function destroy(ListingInquiry $listingInquiry): JsonResponse
    {
        $this->authorize('delete', $listingInquiry);

        $listingInquiry->delete();

        return response()->json(['message' => 'Inquiry deleted.']);
    }
}