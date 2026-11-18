<?php

namespace App\Http\Controllers\FhOne;

use App\Http\Controllers\Controller;
use App\Services\FhOne\ProjectListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GET /api/fh-one/v1/projects?q=&live=1&page=&per_page=&sort=listings|live|name&dir=desc
 *
 * Every project that has at least one listing, with how many and how many are
 * live — searchable by name (`q`), paged like /metrics/{metric}/records (up to
 * 200 a page). Behind VerifyFhOneKey.
 */
class ProjectController extends Controller
{
    public function index(Request $request, ProjectListingService $projects): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'live' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.ProjectListingService::MAX_PER_PAGE],
            'sort' => ['nullable', Rule::in(array_keys(ProjectListingService::SORTS))],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $sort = $data['sort'] ?? 'listings';
        // Counts read best biggest first; names A–Z.
        $dir = $data['dir'] ?? ($sort === 'name' ? 'asc' : 'desc');

        return response()->json([
            'q' => (string) ($data['q'] ?? ''),
            'live_only' => (bool) ($data['live'] ?? false),
            'filter' => 'project_id',
        ] + $projects->list(
            (string) ($data['q'] ?? ''),
            (bool) ($data['live'] ?? false),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 25),
            $sort,
            $dir,
        ) + [
            'generated_at' => Carbon::now('Asia/Manila')->toIso8601String(),
        ]);
    }
}
