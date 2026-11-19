<?php

namespace App\Http\Controllers\FhOne;

use App\Http\Controllers\Controller;
use App\Services\FhOne\InvalidAsk;
use App\Services\FhOne\MetricService;
use App\Services\FhOne\ProjectListingService;
use App\Services\Reports\Executive\DrillService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/fh-one/v1/projects?q=&live=1&page=&per_page=&sort=listings|live|name&dir=desc
 *   Every project that has at least one listing, with how many and how many
 *   are live — searchable by name (`q`), paged (up to 200 a page).
 *
 * GET /api/fh-one/v1/projects/{slug}
 *   One of those projects: its counts plus the project page's public details
 *   (address, map position, photos, starting price, website link).
 *
 * GET /api/fh-one/v1/projects/{slug}/listings?live=1&page=&per_page=&sort=added|price&dir=desc
 *   Its listings — the records of listings.total (or listings.live with
 *   live=1) under project_id, so the rows are the ones the counts count.
 *
 * {slug} is the website's (filipinohomes.com/projects/{slug}); a numeric id works too.
 *
 * Behind VerifyFhOneKey.
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

    /** GET /v1/projects/{project}: one project's details, by slug (or id). */
    public function show(string $project, ProjectListingService $projects): JsonResponse
    {
        $details = $projects->details($project);
        if ($details === null) {
            return $this->notFound($project);
        }

        return response()->json([
            'project' => $details,
            'generated_at' => Carbon::now('Asia/Manila')->toIso8601String(),
        ]);
    }

    /** GET /v1/projects/{project}/listings: that project's listings, paged, by slug (or id). */
    public function listings(Request $request, string $project, ProjectListingService $projects, MetricService $metrics): JsonResponse
    {
        $data = $request->validate([
            'live' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.DrillService::MAX_PER_PAGE],
            'sort' => ['nullable', 'string', 'max:32'],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $found = $projects->find($project);
        if ($found === null) {
            return $this->notFound($project);
        }

        $liveOnly = (bool) ($data['live'] ?? false);
        // Both metrics are "as of now"; the range is required by the drill but not used.
        $today = ReportRange::today();

        try {
            $records = $metrics->records(
                $liveOnly ? 'listings.live' : 'listings.total',
                ReportRange::resolve($today, $today),
                ['project_id' => $found['id']],
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 25),
                $data['sort'] ?? null,
                $data['dir'] ?? null,
            );
        } catch (InvalidAsk $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return response()->json([
            'project' => $found,
            'live_only' => $liveOnly,
            'columns' => $records['columns'],
            'rows' => $records['rows'],
            'sort' => $records['sort'],
            'meta' => $records['meta'],
            'measured' => $records['measured'],
            'generated_at' => Carbon::now('Asia/Manila')->toIso8601String(),
        ]);
    }

    private function notFound(string $project): JsonResponse
    {
        return response()->json([
            'error' => 'project_not_found',
            'message' => "No project '{$project}' with listings. GET /api/fh-one/v1/projects lists them.",
        ], 404);
    }
}
