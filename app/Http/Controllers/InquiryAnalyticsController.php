<?php

namespace App\Http\Controllers;

use App\Services\Inquiry\InquiryHeatmapService;
use App\Services\Inquiry\InquiryListingsService;
use App\Services\Inquiry\InquiryLocationService;
use App\Services\Inquiry\InquiryOriginService;
use App\Services\Inquiry\InquiryOverviewService;
use App\Services\Inquiry\InquiryTopClientsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only deep Inquiry Analytics over the live chats (type='listing')
 * inquiry model. Route-gated by RoleMiddleware:admin. Distinct from
 * InquiryController, which handles the unrelated Contact-Us inbox.
 */
class InquiryAnalyticsController extends Controller
{
    /** Filters shared by every endpoint. */
    private function validateFilters(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'date_from'     => 'nullable|date',
            'date_to'       => 'nullable|date|after_or_equal:date_from',
            'category_id'   => 'nullable|integer|exists:categories,id',
            'property_type' => 'nullable|string|max:100',
            // Multi-select (CSV of ids) — preferred over the singular params above.
            'category_ids'  => ['nullable', 'string', 'max:255', 'regex:/^\d+(,\d+)*$/'],
            'type_ids'      => ['nullable', 'string', 'max:255', 'regex:/^\d+(,\d+)*$/'],
            'subtype_ids'   => ['nullable', 'string', 'max:500', 'regex:/^\d+(,\d+)*$/'],
            'island'        => 'nullable|in:luzon,visayas,mindanao',
            'province_id'   => 'nullable|integer|exists:provinces,id',
            'city_id'       => 'nullable|integer|exists:cities,id',
            'barangay_id'   => 'nullable|integer|exists:barangays,id',
            // Inquiring-client origin scope (user_info geo). Applies to every
            // endpoint via the shared base; also drives the Inquiry Origin tab.
            'origin_country' => 'nullable|string|max:8',
            'origin_region'  => 'nullable|string|max:120',
            'origin_city'    => 'nullable|string|max:120',
        ], $extra));
    }

    public function overview(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return response()->json(
            (new InquiryOverviewService())->configure($filters)->overview()
        );
    }

    public function locations(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return response()->json(
            (new InquiryLocationService())->configure($filters)->locations()
        );
    }

    /** "Where the inquiry came from" — group by the inquiring client's origin. */
    public function origins(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return response()->json(
            (new InquiryOriginService())->configure($filters)->origins()
        );
    }

    public function clients(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, [
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by'  => 'nullable|in:count,name',
            'sort_dir' => 'nullable|in:asc,desc',
        ]);

        return response()->json(
            (new InquiryTopClientsService())->configure($filters)->clients(
                (int) ($filters['page'] ?? 1),
                (int) ($filters['per_page'] ?? 25),
                $filters['sort_by'] ?? 'count',
                $filters['sort_dir'] ?? 'desc',
            )
        );
    }

    public function heatmap(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        return response()->json(
            (new InquiryHeatmapService())->configure($filters)->points()
        );
    }

    public function clusters(Request $request): JsonResponse
    {
        // Viewport-driven: zoom-derived level + on-screen bounding box.
        $filters = $this->validateFilters($request, [
            'level'   => 'nullable|in:island,province,city,barangay',
            'min_lat' => 'nullable|numeric',
            'max_lat' => 'nullable|numeric',
            'min_lng' => 'nullable|numeric',
            'max_lng' => 'nullable|numeric',
        ]);

        return response()->json(
            (new InquiryHeatmapService())->configure($filters)->clusters()
        );
    }

    /** Per-listing list behind an inquiry count (location / client / cluster scope). */
    public function listings(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, [
            'client_id' => 'nullable|integer|exists:users,id',
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:100',
            'sort_by'   => 'nullable|in:inquiries,price,newest',
            'sort_dir'  => 'nullable|in:asc,desc',
        ]);

        return response()->json(
            (new InquiryListingsService())->configure($filters)->listings(
                (int) ($filters['page'] ?? 1),
                (int) ($filters['per_page'] ?? 25),
                $filters['sort_by'] ?? 'inquiries',
                $filters['sort_dir'] ?? 'desc',
            )
        );
    }

    /** Master table: every inquiry in scope (flat, searchable, paginated). */
    public function inquiries(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, [
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by'  => 'nullable|in:date,price,client,listing',
            'sort_dir' => 'nullable|in:asc,desc',
            'search'   => 'nullable|string|max:120',
        ]);

        return response()->json(
            (new InquiryListingsService())->configure($filters)->inquiries(
                (int) ($filters['page'] ?? 1),
                (int) ($filters['per_page'] ?? 25),
                $filters['sort_by'] ?? 'date',
                $filters['sort_dir'] ?? 'desc',
                $filters['search'] ?? null,
            )
        );
    }

    /** Individual inquiries on a single listing (client + date), within scope. */
    public function listingInquiries(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request, [
            'listing_id' => 'required|integer|exists:listings,id',
            'client_id'  => 'nullable|integer|exists:users,id',
            'page'       => 'nullable|integer|min:1',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json(
            (new InquiryListingsService())->configure($filters)->listingInquiries(
                (int) $filters['listing_id'],
                (int) ($filters['page'] ?? 1),
                (int) ($filters['per_page'] ?? 25),
            )
        );
    }
}
