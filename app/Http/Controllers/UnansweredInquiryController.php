<?php

namespace App\Http\Controllers;

use App\Services\Inquiry\UnansweredInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin sidebar → Unanswered Inquiries. See App\Services\Inquiry\UnansweredInquiryService.
 *
 *   GET /admin/inquiries/unanswered?filter=all|office_number|waiting|inactive_agent&q=&page=
 */
class UnansweredInquiryController extends Controller
{
    public function index(Request $request, UnansweredInquiryService $service): JsonResponse
    {
        $v = $request->validate([
            'filter' => ['sometimes', Rule::in(UnansweredInquiryService::FILTERS)],
            'q' => 'sometimes|nullable|string|max:100',
            'per_page' => 'sometimes|integer|min:5|max:100',
        ]);
        $filter = $v['filter'] ?? 'all';
        $page = $service->paginate($filter, $v['q'] ?? null, (int) ($v['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => $service->counts($v['q'] ?? null),
        ]);
    }
}
