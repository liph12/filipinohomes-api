<?php

namespace App\Http\Controllers;

use App\Http\Requests\RebrandFacilityRequest;
use App\Http\Requests\StoreFacilityRequest;
use App\Http\Requests\UpdateFacilityRequest;
use App\Jobs\PingIndexNow;
use App\Models\Facility;
use App\Services\IndexNowService;
use App\Services\Office\GoogleGeocodingService;
use App\Services\Seo\FacilityCountService;
use App\Services\Seo\FacilityRebrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin CRUD for the curated "near {facility}" SEO registry (admin SEO
 * Manage page). Registry mutations only — derived facility_listing_counts
 * rows are written exclusively by FacilityCountService / the nightly compute.
 *
 * Invariants enforced here and in the services:
 *   - slug is server-generated on create and never edited directly; renames
 *     go through rebrand() (FacilityRebrandService owns the alias/301 rule)
 *   - facilities are deactivated, never hard-deleted (indexed URLs need
 *     their slug history for 301/noindex handling)
 *   - the ≥MIN_LISTINGS floor is computed, never editable — this controller
 *     exposes previews of it, not knobs for it
 */
class FacilityAdminController extends Controller
{
    public function __construct(
        private readonly GoogleGeocodingService $geocoder,
        private readonly FacilityCountService $counts,
        private readonly FacilityRebrandService $rebrander,
        private readonly IndexNowService $indexNow,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Facility::query();

        if (($category = (string) $request->query('category', '')) !== '') {
            $query->where('category', $category);
        }
        if (($city = (string) $request->query('city', '')) !== '') {
            $query->where('city', $city);
        }
        if (($status = (string) $request->query('status', '')) !== '') {
            $query->where('is_active', $status === 'active');
        }
        if ($request->query('geocoded') === 'missing') {
            $query->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'));
        }
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $term = "%{$search}%";
            $query->where(function ($q) use ($term) {
                $q->where('name', 'LIKE', $term)
                    ->orWhere('slug', 'LIKE', $term)
                    ->orWhere('city', 'LIKE', $term);
            });
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $paginator = $query->orderBy('name')->paginate($perPage);

        // Live cohort stats per facility from the derived table — how many
        // (category × type) pages each facility currently generates and its
        // strongest cohort, so the table can show a live/gated badge.
        $ids = collect($paginator->items())->pluck('id');
        $stats = DB::table('facility_listing_counts')
            ->whereIn('facility_id', $ids)
            ->selectRaw('facility_id, COUNT(*) as cohorts, MAX(total) as max_total')
            ->groupBy('facility_id')
            ->get()
            ->keyBy('facility_id');

        $data = collect($paginator->items())->map(function (Facility $f) use ($stats) {
            $s = $stats->get($f->id);

            return array_merge($f->toArray(), [
                'live_cohorts' => (int) ($s->cohorts ?? 0),
                'max_total'    => (int) ($s->max_total ?? 0),
            ]);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreFacilityRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if ($slug === '' || Facility::slugInUse($slug)) {
            // 422 rather than silently suffixing: a slug collision usually
            // means the facility already exists (possibly under a former
            // name) — the admin should decide, not the machine.
            return response()->json([
                'message' => $slug === ''
                    ? 'That name does not produce a usable URL slug.'
                    : "A facility with the slug \"{$slug}\" already exists (as a current or former slug).",
            ], 422);
        }

        $lat = $validated['lat'] ?? null;
        $lng = $validated['lng'] ?? null;
        $geocodeFailed = false;

        // Inline geocode when no manual coordinates were given — one Google
        // call (~$0.005) inside an admin request is fine. Failure is not
        // fatal: the row saves without coords (scopeGeocoded keeps it out of
        // compute) and the UI offers a retry + manual fields.
        if ($lat === null && $this->geocoder->hasApiKey()) {
            try {
                $coords = $this->geocoder->geocode(
                    "{$validated['name']}, {$validated['city']}, {$validated['province']}"
                );
                if ($coords !== null) {
                    $lat = $coords['lat'];
                    $lng = $coords['lng'];
                } else {
                    $geocodeFailed = true;
                }
            } catch (Throwable) {
                $geocodeFailed = true;
            }
        }

        $facility = new Facility([
            'name'      => $validated['name'],
            'slug'      => $slug,
            'category'  => $validated['category'],
            'city'      => $validated['city'],
            'province'  => $validated['province'],
            'lat'       => $lat,
            'lng'       => $lng,
            'is_active' => true,
        ]);
        $facility->auditDescription = "Created facility: {$validated['name']} ({$validated['category']}, {$validated['city']})";
        $facility->auditSource = 'seo_admin';
        $facility->save();

        return response()->json([
            'data'           => $facility,
            'geocode_failed' => $geocodeFailed,
        ], 201);
    }

    public function show(Facility $facility): JsonResponse
    {
        $cohorts = DB::table('facility_listing_counts')
            ->where('facility_id', $facility->id)
            ->orderByDesc('total')
            ->get(['category', 'type', 'total', 'computed_at']);

        return response()->json([
            'data'         => $facility,
            'live_cohorts' => $cohorts,
        ]);
    }

    public function update(UpdateFacilityRequest $request, Facility $facility): JsonResponse
    {
        $validated = $request->validated();
        // Recompute is warranted when the pin moved — surface that to the UI.
        $coordsChanged = array_key_exists('lat', $validated)
            && ((float) $validated['lat'] !== $facility->lat || (float) $validated['lng'] !== $facility->lng);

        $facility->auditSource = 'seo_admin';
        $facility->update($validated);

        return response()->json([
            'data'              => $facility->refresh(),
            'recompute_advised' => $coordsChanged,
        ]);
    }

    /** Soft-retire. Hard deletion is intentionally unsupported (301 history). */
    public function deactivate(Facility $facility): JsonResponse
    {
        $facility->auditDescription = "Deactivated facility: {$facility->name} — its pages drop from the sitemap on the next compute";
        $facility->auditSource = 'seo_admin';
        $facility->update(['is_active' => false]);

        return response()->json(['data' => $facility->refresh()]);
    }

    public function activate(Facility $facility): JsonResponse
    {
        $facility->auditDescription = "Reactivated facility: {$facility->name}";
        $facility->auditSource = 'seo_admin';
        $facility->update(['is_active' => true]);

        return response()->json(['data' => $facility->refresh()]);
    }

    public function rebrand(RebrandFacilityRequest $request, Facility $facility): JsonResponse
    {
        $validated = $request->validated();

        $facility = $this->rebrander->rebrand(
            $facility,
            $validated['new_name'],
            $validated['new_slug'] ?? null,
        );

        return response()->json(['data' => $facility]);
    }

    /** Manual geocode retry (route-throttled — Google bills per lookup). */
    public function geocode(Facility $facility): JsonResponse
    {
        if (! $this->geocoder->hasApiKey()) {
            return response()->json(['message' => 'Google Maps geocoding is not configured on this environment.'], 422);
        }

        $coords = $this->geocoder->geocode("{$facility->name}, {$facility->city}, {$facility->province}");
        if ($coords === null) {
            return response()->json(['message' => 'Geocoding returned no result — set the coordinates manually.'], 422);
        }

        $facility->auditDescription = "Geocoded facility: {$facility->name} → {$coords['lat']}, {$coords['lng']}";
        $facility->auditSource = 'seo_admin';
        $facility->update(['lat' => $coords['lat'], 'lng' => $coords['lng']]);

        return response()->json(['data' => $facility->refresh()]);
    }

    /**
     * Pre-save verdict for the create form: cohort counts at this pin,
     * including below-floor cohorts, plus whether any clears the ≥10 gate.
     */
    public function previewCount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        return response()->json(
            $this->counts->previewCounts((float) $validated['lat'], (float) $validated['lng'])
        );
    }

    /**
     * Recompute ONE facility's derived count rows now (seconds) so a new or
     * re-pinned facility's pages go live without waiting for the 04:00 run.
     */
    public function recompute(Facility $facility): JsonResponse
    {
        $written = $this->counts->recomputeFacility($facility);

        return response()->json([
            'data' => [
                'facility_id'  => $facility->id,
                'rows_written' => $written,
            ],
        ]);
    }

    /**
     * Queue an IndexNow ping for every URL this facility currently generates.
     * Only floor-clearing cohorts exist in the derived table, so this can
     * never advertise a below-floor URL.
     */
    public function pingIndexNow(Facility $facility): JsonResponse
    {
        $rows = DB::table('facility_listing_counts')
            ->where('facility_id', $facility->id)
            ->get(['category', 'type', 'city', 'province']);

        if ($rows->isEmpty()) {
            return response()->json([
                'message' => 'No live pages for this facility yet — recompute its counts first (and check it clears the ≥10 floor).',
            ], 422);
        }

        $urls = $rows->map(fn ($r) => $this->indexNow->nearFacilityUrl(
            (string) $r->category,
            (string) $r->type,
            $facility->slug,
            (string) $r->city,
            (string) $r->province,
        ))->filter()->unique()->values()->all();

        if ($urls === []) {
            return response()->json(['message' => 'No valid URLs to ping.'], 422);
        }

        PingIndexNow::dispatch($urls)->afterCommit();

        return response()->json(['data' => ['urls' => $urls]], 202);
    }
}
