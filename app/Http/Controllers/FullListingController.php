<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Mail\AtsStatusUpdatedMailer;
use App\Models\Listing;
use App\Services\Listing\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use OwenIt\Auditing\Events\AuditCustom;
use App\Http\Middleware\RoleMiddleware;
class FullListingController extends Controller
{
    public function __construct(protected ListingService $listingService)
    {
        $this->middleware('auth:sanctum'); 
        $this->middleware(RoleMiddleware::class . ':agent,admin')->only(['store','show','update']);
    }
    public function store(StoreListingRequest $request): JsonResponse
    {
        try {
            $listing = $this->listingService->createListing(
                $request->validated(),
                $request->user()->agent
            );

            return response()->json([
                'message' => 'Listing created successfully',
                 'data' => $listing->toArray(),
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create listing',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }
    public function show(Listing $listing): JsonResponse
    {
        // Load relations needed to resolve address hierarchy + AI context
        // (category, project, amenities — the audit modal's AI prompts use
        // these for richer title/description generation).
        $listing->load([
            'property.propertyAttribute.subtype.type',
            'property.furnishing',
            'property.barangay.city.province',
            'property.nearbyFacility',
            'property.project',
            'category',
            'agent.user',
        ]);

        // Transform response so property.address_id contains barangay + city + province info
        $payload = $listing->toArray();

        // Mirror ListingResource's `date_added` (the listing's creation date) so
        // the audit header can show "Added <date>" on deep-linked listings too,
        // not just ones opened from the grid.
        $payload['date_added'] = $listing->created_at?->toDateString();

        try {
            $barangay = optional($listing->property)->barangay;
            if ($barangay) {
                $city = optional($barangay)->city;
                $province = optional($city)->province;

                $payload['property']['address_id'] = [
                    'id'   => $barangay->id,
                    'name' => $barangay->name,
                    'city' => $city ? [
                        'id'         => $city->id,
                        'name'       => $city->name,
                        'postalcode' => $city->postalcode,
                    ] : null,
                    'province' => $province ? [
                        'id'   => $province->id,
                        'name' => $province->name,
                        'code' => $province->code,
                    ] : null,
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort; ignore transformation errors
        }

        return response()->json([
            'data' => $payload,
        ]);
    }
    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        try {
            $user  = $request->user();
            $agent = $user->agent;

            if ($user->role->name === 'agent' && (int) $listing->agent_id !== (int) ($agent?->id)) {
                return response()->json(['message' => 'You do not own this listing.'], 403);
            }

            $payload = $request->validated();

            // Only admins may update ATS status; reject agents attempting to change it
            if (array_key_exists('ats_status', $payload) && ($user->role->name ?? null) !== 'admin') {
                return response()->json([
                    'message' => 'Only admins can update ATS status.'
                ], 403);
            }

            // Snapshot the ATS status + remarks before the update so we can email
            // the listing's agent if either changes (see the send below).
            $oldAtsStatus  = optional($listing->property)->ats_status;
            $oldAtsRemarks = optional($listing->property)->ats_remarks;

            // Capture snapshot before update if the LISTING OWNER edits a
            // flagged listing. Owner is whoever's agent.id matches the
            // listing's agent_id — works for plain agents AND for admins who
            // own listings (admins still have an Agent record). Auditor
            // admins who don't own this listing don't trigger the transition
            // here; their edits go through the audit modal and write to
            // audit_edited_fields instead.
            // Cast both sides to int — depending on PDO settings, agent_id
            // can come back as a string while $agent->id is int (because it's
            // a model primary key). A strict === between "5" and 5 would
            // silently fail and the listing would stay 'flagged' even after
            // the owner edited it.
            $isFlaggedOwnerEdit = $listing->verification_status === 'flagged'
                && $agent !== null
                && (int) $listing->agent_id === (int) $agent->id;

            $snapName          = $listing->name;
            $snapPrice         = (string) $listing->price;
            $snapDescription   = '';
            $snapAddress       = '';
            $snapBeds          = null;
            $snapBaths         = null;
            $snapGarage        = null;
            $snapLotArea       = null;
            $snapFloorArea     = null;
            $snapFeaturedCount = 0;
            $snapPhotosCount   = 0;

            if ($isFlaggedOwnerEdit) {
                $listing->load('property.propertyAttribute');
                $snapDescription   = (string) ($listing->property->description ?? '');
                $snapAddress       = (string) ($listing->property->address ?? '');
                $snapBeds          = $listing->property->propertyAttribute->bedroom_count ?? null;
                $snapBaths         = $listing->property->propertyAttribute->bathroom_count ?? null;
                $snapGarage        = $listing->property->propertyAttribute->garage_count ?? null;
                $snapLotArea       = $listing->property->propertyAttribute->lot_area ?? null;
                $snapFloorArea     = $listing->property->propertyAttribute->floor_area ?? null;
                $snapFeaturedCount = count((array) ($listing->featured_photo ?? []));
                $snapPhotosCount   = count((array) ($listing->property->photos ?? []));
            }

            // Tag the source so the audit row written by the service-layer
            // save is attributed to the full edit form rather than the
            // default route-name fallback.
            $listing->auditSource = 'edit_listing_form';
            if ($listing->property) {
                $listing->property->auditSource = 'edit_listing_form';
            }

            $updated = $this->listingService->updateListing(
                $payload,
                $listing,
                $agent
            );

            // Check the flag we attached in the Service
            if (!$updated->was_actually_updated) {
                return response()->json([
                    'message' => 'No changes detected. Listing is already up to date.',
                    'data'    => $updated->toArray(),
                ], 200);
            }

            // If the listing owner edited a flagged listing, compute the diff
            // and bump it back to pending_review for the audit team to look at.
            if ($isFlaggedOwnerEdit) {
                $updated->load('property.propertyAttribute');
                $agentEdited = [];

                $cmp = fn($a, $b) => trim((string) $a) !== trim((string) $b);

                if ($cmp($snapName, $updated->name)) {
                    $agentEdited[] = ['label' => 'Title', 'original' => $snapName, 'current' => $updated->name];
                }
                if ($cmp($snapPrice, $updated->price)) {
                    $agentEdited[] = ['label' => 'Price', 'original' => $snapPrice, 'current' => (string) $updated->price];
                }
                $newDesc = (string) ($updated->property->description ?? '');
                if ($cmp($snapDescription, $newDesc)) {
                    $agentEdited[] = ['label' => 'Description', 'original' => $snapDescription, 'current' => $newDesc];
                }
                $newAddr = (string) ($updated->property->address ?? '');
                if ($cmp($snapAddress, $newAddr)) {
                    $agentEdited[] = ['label' => 'Address', 'original' => $snapAddress, 'current' => $newAddr];
                }
                $newBeds = $updated->property->propertyAttribute->bedroom_count ?? null;
                if ($snapBeds !== $newBeds) {
                    $agentEdited[] = ['label' => 'Bedrooms', 'original' => (string) $snapBeds, 'current' => (string) $newBeds];
                }
                $newBaths = $updated->property->propertyAttribute->bathroom_count ?? null;
                if ($snapBaths !== $newBaths) {
                    $agentEdited[] = ['label' => 'Bathrooms', 'original' => (string) $snapBaths, 'current' => (string) $newBaths];
                }
                $newGarage = $updated->property->propertyAttribute->garage_count ?? null;
                if ($snapGarage !== $newGarage) {
                    $agentEdited[] = ['label' => 'Garage', 'original' => (string) $snapGarage, 'current' => (string) $newGarage];
                }
                $newLot = $updated->property->propertyAttribute->lot_area ?? null;
                if ($snapLotArea !== $newLot) {
                    $agentEdited[] = ['label' => 'Lot Area', 'original' => (string) $snapLotArea, 'current' => (string) $newLot];
                }
                $newFloor = $updated->property->propertyAttribute->floor_area ?? null;
                if ($snapFloorArea !== $newFloor) {
                    $agentEdited[] = ['label' => 'Floor Area', 'original' => (string) $snapFloorArea, 'current' => (string) $newFloor];
                }
                $newFeaturedCount = count((array) ($updated->featured_photo ?? []));
                if ($snapFeaturedCount !== $newFeaturedCount) {
                    $agentEdited[] = ['label' => 'Featured Photo', 'original' => "{$snapFeaturedCount} photo(s)", 'current' => "{$newFeaturedCount} photo(s)"];
                }
                $newPhotosCount = count((array) ($updated->property->photos ?? []));
                if ($snapPhotosCount !== $newPhotosCount) {
                    $agentEdited[] = ['label' => 'Gallery Photos', 'original' => "{$snapPhotosCount} photo(s)", 'current' => "{$newPhotosCount} photo(s)"];
                }

                $updated->updateQuietly([
                    'verification_status' => 'pending_review',
                    'agent_edited_fields' => $agentEdited,
                    're_submitted_at'     => now(),
                ]);
                $updated->refresh();

                // Custom audit event for the resubmission so it surfaces
                // under category=listings_audit, separate from the regular
                // 'updated' audit that fired for the service-layer save.
                $updated->auditEvent             = 'resubmitted';
                $updated->isCustomEvent          = true;
                $updated->auditCategoryOverride  = 'listings_audit';
                $updated->auditSource            = 'edit_listing_form';
                $updated->auditDescription       = 'Owner edited flagged listing → pending_review';
                $updated->auditCustomOld         = ['verification_status' => 'flagged'];
                $updated->auditCustomNew         = [
                    'verification_status' => 'pending_review',
                    'edited_fields'       => $agentEdited,
                ];
                Event::dispatch(new AuditCustom($updated));
            }

            // Email the listing's agent when the ATS status OR the ATS remarks
            // changed, so they know the outcome and can act on it. Each side is
            // only considered when that field was actually submitted.
            $newAtsStatus   = optional($updated->property)->ats_status;
            $newAtsRemarks  = optional($updated->property)->ats_remarks;
            $statusChanged  = array_key_exists('ats_status', $payload)
                && $newAtsStatus !== null && $newAtsStatus !== $oldAtsStatus;
            $remarksChanged = array_key_exists('ats_remarks', $payload)
                && $newAtsRemarks !== $oldAtsRemarks;
            if ($statusChanged || $remarksChanged) {
                $this->notifyAtsStatusChanged($updated, (string) $newAtsStatus);
            }

            return response()->json([
                'message' => 'Listing updated successfully',
                'data'    => $updated->toArray(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update listing',
                'error'   => config('app.debug') ? $e->getMessage() : 'An error occurred',
            ], 500);
        }
    }

    /**
     * Email the listing's agent that its ATS status changed. Best-effort: any
     * failure is logged, never thrown, so the update response still succeeds.
     */
    private function notifyAtsStatusChanged(Listing $listing, string $rawStatus): void
    {
        try {
            $listing->loadMissing('agent.user.role', 'property');
            $agentUser = optional($listing->agent)->user;
            if (! $agentUser || ! $agentUser->email) {
                return;
            }

            // Backend enum → display label ("approve" → "Approved").
            $display = ucfirst($rawStatus === 'approve' ? 'approved' : $rawStatus);

            $expRaw     = optional($listing->property)->ats_expiration_date;
            $expiration = $expRaw ? Carbon::parse($expRaw)->format('F j, Y') : null;

            $photos        = $listing->featured_photo; // cast to array on the model
            $featuredPhoto = is_array($photos) && count($photos) > 0 ? $photos[0] : null;

            $roleSegment = optional($agentUser->role)->name === 'admin' ? 'admin' : 'agent';
            $listingUrl  = 'https://filipinohomes.com/'.$roleSegment.'/create-listing?edit='.$listing->id;

            Mail::to($agentUser->email)->send(new AtsStatusUpdatedMailer(
                agentName: $agentUser->name ?? 'Agent',
                listingTitle: $listing->name,
                listingCode: $listing->code,
                atsStatus: $display,
                atsRemarks: optional($listing->property)->ats_remarks,
                atsExpiration: $expiration,
                listingUrl: $listingUrl,
                featuredPhoto: $featuredPhoto,
            ));

            Log::info('ATS status email sent', [
                'listing_id' => $listing->id,
                'status'     => $rawStatus,
                'to'         => $agentUser->email,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ATS status email failed', [
                'listing_id' => $listing->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}