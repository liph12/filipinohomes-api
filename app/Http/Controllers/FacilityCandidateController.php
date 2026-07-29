<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Facility;
use App\Models\FacilityCandidate;
use App\Services\Seo\FacilityCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Review queue for scanner-discovered facility candidates (admin SEO Manage →
 * Candidates). Candidates are read-only output of facilities:scan-candidates;
 * the only mutations here are the human review verbs:
 *
 *   approve → creates a live Facility (slug server-generated, 422 on
 *             collision), recomputes its counts so pages go live immediately,
 *             and audits under `seo`
 *   dismiss → hides it from the queue (reversible via restore; rescans never
 *             resurrect a dismissal)
 */
class FacilityCandidateController extends Controller
{
    public function __construct(private readonly FacilityCountService $counts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = FacilityCandidate::query();

        $status = (string) $request->query('status', FacilityCandidate::STATUS_PENDING);
        if ($status === FacilityCandidate::STATUS_PENDING) {
            // Default queue excludes candidates matched to an existing
            // facility — they're duplicates, not decisions.
            $query->pending();
        } elseif (in_array($status, [FacilityCandidate::STATUS_APPROVED, FacilityCandidate::STATUS_DISMISSED], true)) {
            $query->where('status', $status);
        } elseif ($status === 'matched') {
            $query->whereNotNull('matched_facility_id');
        }

        if (($category = (string) $request->query('category', '')) !== '') {
            $query->where('category', $category);
        }
        if ($request->boolean('clears_floor')) {
            $query->clearsFloor();
        }
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('city', 'LIKE', $term)
                    ->orWhere('province', 'LIKE', $term);
            });
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $paginator = $query
            ->orderByDesc('clears_floor')
            ->orderByDesc('max_total')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
            // Header counters for the tile (cheap on an indexed table).
            'counts' => [
                'pending'        => FacilityCandidate::query()->pending()->count(),
                'pending_clears' => FacilityCandidate::query()->pending()->clearsFloor()->count(),
            ],
        ]);
    }

    /** Promote a candidate into the live registry. */
    public function approve(Request $request, FacilityCandidate $candidate): JsonResponse
    {
        if ($candidate->status === FacilityCandidate::STATUS_APPROVED) {
            return response()->json(['message' => 'This candidate is already approved.'], 422);
        }

        $slug = Str::slug($candidate->name);
        if ($slug === '' || Facility::slugInUse($slug)) {
            return response()->json([
                'message' => $slug === ''
                    ? 'This candidate\'s name does not produce a usable URL slug — add it manually with a better name.'
                    : "A facility with the slug \"{$slug}\" already exists (as a current or former slug). Dismiss this candidate or rename the existing facility.",
            ], 422);
        }

        $facility = new Facility([
            'name'      => $candidate->name,
            'slug'      => $slug,
            'category'  => $candidate->category,
            'city'      => $candidate->city,
            'province'  => $candidate->province,
            'lat'       => $candidate->lat,
            'lng'       => $candidate->lng,
            'is_active' => true,
        ]);
        $facility->auditDescription = "Approved scanner candidate: {$candidate->name} ({$candidate->category}, {$candidate->city}) — projected {$candidate->max_total} listings";
        $facility->auditSource = 'seo_admin';
        $facility->save();

        // Counts live immediately — the admin just reviewed the projection,
        // no reason to wait for tonight's 04:00 rebuild.
        $written = $this->counts->recomputeFacility($facility);

        $candidate->update([
            'status'               => FacilityCandidate::STATUS_APPROVED,
            'approved_facility_id' => $facility->id,
        ]);

        return response()->json([
            'data' => [
                'facility'     => $facility,
                'rows_written' => $written,
            ],
        ], 201);
    }

    public function dismiss(Request $request, FacilityCandidate $candidate): JsonResponse
    {
        $candidate->update(['status' => FacilityCandidate::STATUS_DISMISSED]);
        $this->auditReview($request, $candidate, 'dismissed');

        return response()->json(['data' => $candidate->refresh()]);
    }

    public function restore(Request $request, FacilityCandidate $candidate): JsonResponse
    {
        $candidate->update(['status' => FacilityCandidate::STATUS_PENDING]);
        $this->auditReview($request, $candidate, 'restored to pending');

        return response()->json(['data' => $candidate->refresh()]);
    }

    /**
     * Dismiss/restore aren't Facility-model mutations, so audit them with a
     * direct row under `seo` (approve is audited via the Facility create).
     * Defensive like the other audit services — bookkeeping never breaks the
     * review flow.
     */
    private function auditReview(Request $request, FacilityCandidate $candidate, string $verb): void
    {
        try {
            $user = $request->user();
            Audit::create([
                'user_id'        => $user?->id,
                'user_type'      => $user ? \App\Models\User::class : null,
                'user_role'      => $user?->role?->name,
                'user_name'      => $user?->name,
                'event'          => 'candidate_reviewed',
                'category'       => 'seo',
                'source'         => 'seo_admin',
                'auditable_type' => FacilityCandidate::class,
                'auditable_id'   => $candidate->id,
                'subject_label'  => $candidate->name,
                'description'    => "{$user?->name} {$verb} candidate: {$candidate->name} ({$candidate->category}, {$candidate->city})",
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'url'            => $request->fullUrl(),
                'old_values'     => null,
                'new_values'     => ['candidate_id' => $candidate->id, 'action' => $verb],
            ]);
        } catch (Throwable $e) {
            Log::warning('SEO audit (candidate_reviewed) write failed', [
                'candidate_id' => $candidate->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
