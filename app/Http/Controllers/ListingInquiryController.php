<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\ListingInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

public function store(Request $request): JsonResponse
{
    $request->validate([
        'listing_id' => ['required', 'integer', 'exists:listings,id'],
        'message'    => ['required', 'string', 'max:2000'],
    ]);

    $listing = Listing::findOrFail($request->listing_id);
    $user    = $request->user();

    $ipResponse = Http::get('https://socket.leuteriorealty.com/user-info');
    $ipData = $ipResponse->json();
    $clientIp = $ipData['ip'] ?? $request->ip();

    $geoResponse = Http::get('https://api.leuteriorealty.com/core-system/v1/public/api/user-info', [
        'ip' => $clientIp
    ]);
    $geoData = $geoResponse->json();

    $coordinates = null;
    if (!empty($geoData['location'])) {
        [$lat, $lng] = explode(',', $geoData['location']);
        $coordinates = [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
        ];
    }

    $inquiry = ListingInquiry::firstOrCreate(
        [
            'listing_id' => $listing->id,
            'client_id'  => $user->id,
        ],
        [
            'agent_id' => $listing->agent_id,
            'status'   => 'pending',
            'geo_coordinates' => $coordinates,
        ]
    );

    // Add opening message
    $inquiry->conversations()->create([
        'sender_id' => $user->id,
        'message'   => $request->message,
    ]);

    if ($inquiry->status === 'pending') {
        $inquiry->update(['status' => 'active']);
    }

    // Return safe info only — hide geo
    return response()->json([
        'message'    => 'Inquiry sent successfully.',
        'inquiry_id' => $inquiry->id,
    ], 201);
}
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

    /**
     * Count all listing inquiries (not conversations).
     */
    public function countAll(): \Illuminate\Http\JsonResponse
    {
        $count = ListingInquiry::count();
        return response()->json(['count' => $count]);
    }
}