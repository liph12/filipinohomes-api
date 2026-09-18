<?php

namespace App\Console\Commands;

use App\Models\Facility;
use App\Models\FacilityCandidate;
use App\Services\Seo\FacilityCountService;
use App\Services\Seo\OverpassClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nationwide facility discovery for the "near {facility}" SEO tier.
 *
 * For every city where FilipinoHomes has ≥10 publicly-listed properties with
 * coordinates (a facility anywhere else can never clear the ≥10-listings/
 * 1.5km page floor), queries OpenStreetMap for named malls, universities/
 * colleges, and hospitals, scores each POI with the SAME radius-count query
 * the nightly facility compute uses, and upserts them into the
 * facility_candidates review queue (SEO Manage → Candidates). Candidates are
 * never auto-added to the registry — an admin approves or dismisses each.
 *
 * Resumable by design: cities are processed biggest-inventory-first, each
 * COMPLETED city is checkpointed (cache, FRESH_DAYS TTL) and skipped on the
 * next run (unless --rescore), candidates scored within the last 7 days are
 * skipped per-POI, and upserts are idempotent. Queued runs pass
 * --budget-seconds so the scan exits SUCCESS with a "N remaining" summary
 * before the 15-min RunSeoCommand ceiling can kill it (the 2026-09-18
 * nationwide first pass died exactly that way at 15m01s) — the admin
 * re-runs until 0 remain, each pass skipping checkpointed cities in
 * milliseconds instead of re-spending Overpass time on them. Rescans NEVER
 * touch `status`, so dismissed candidates stay dismissed.
 */
class ScanFacilityCandidates extends Command
{
    protected $signature = 'facilities:scan-candidates
        {--limit= : Max cities to scan this run}
        {--budget-seconds= : Stop gracefully once this much wall-clock has elapsed (queued runs pass this so the 15-min job ceiling is never hit; re-run to continue)}
        {--sleep=2500 : Delay in milliseconds between Overpass queries (public-instance etiquette)}
        {--rescore : Re-score candidates even if scanned within the last 7 days}
        {--dry-run : Query + score but write nothing}';

    protected $description = 'Scan OpenStreetMap for facility candidates (malls/schools/hospitals) in every city with listings.';

    /** Minimum listings a city needs before it is worth scanning. */
    public const MIN_CITY_LISTINGS = 10;

    /** Bbox padding in degrees (~2km) around the listing extent. */
    private const BBOX_PAD = 0.02;

    /** An existing same-category facility within this range = duplicate. */
    private const MATCH_RADIUS_KM = 0.25;

    /** Candidates scored within this window are skipped (fresh). */
    private const FRESH_DAYS = 7;

    /**
     * Plausible Philippines coordinate window. Listing pins outside it are
     * junk (0,0 placeholders, swapped lat/lng — live data contains lat=125.6)
     * and MUST be excluded from the extent aggregation, or a single bad pin
     * inflates a city bbox into an invalid Overpass query (lat > 90 → 400).
     */
    private const PH_LAT_MIN = 4.0;
    private const PH_LAT_MAX = 21.5;
    private const PH_LNG_MIN = 116.0;
    private const PH_LNG_MAX = 127.5;

    /**
     * Max distance (degrees, ~22km) a listing pin may sit from its city's
     * listing centroid and still count toward the scan bbox. PH-bounds alone
     * are not enough: a Cebu City listing mis-pinned in Manila is plausible
     * coordinate-wise but stretched the raw MIN/MAX bbox across half the
     * country (observed: lat 8.5..14.6), producing giant Overpass queries
     * that time out AND mis-attributed POIs. MIN/MAX over centroid-trimmed
     * pins keeps every real metro cluster and amputates the outliers.
     */
    private const TRIM_DEG = 0.2;

    public function handle(OverpassClient $overpass, FacilityCountService $counts): int
    {
        $sleepMs = max(0, (int) $this->option('sleep'));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $budgetSeconds = $this->option('budget-seconds') !== null ? max(1, (int) $this->option('budget-seconds')) : null;
        $startedAt = microtime(true);
        $budgetHit = false;
        $dryRun = (bool) $this->option('dry-run');
        $rescore = (bool) $this->option('rescore');

        $cities = $this->scanAreas($limit);
        $this->info(sprintf('%d city scan area(s)%s.', $cities->count(), $limit ? " (limit {$limit})" : ''));

        // Existing registry snapshot for proximity/slug dedupe (small table).
        $registry = Facility::query()->get(['id', 'slug', 'category', 'lat', 'lng'])->all();

        $stats = ['cities' => 0, 'cities_skipped' => 0, 'pois' => 0, 'new' => 0, 'updated' => 0, 'fresh_skipped' => 0, 'matched' => 0, 'clears' => 0, 'failed_cities' => 0];

        foreach ($cities as $i => $area) {
            // Wall-clock budget (queued runs): stop BEFORE starting another
            // city so the queue worker never has to kill us mid-write.
            if ($budgetSeconds !== null && (microtime(true) - $startedAt) >= $budgetSeconds) {
                $budgetHit = true;
                break;
            }

            // City checkpoint: a city fully scanned within FRESH_DAYS is
            // skipped outright — no Overpass query, no etiquette sleep — so
            // resume runs reach unscanned ground in milliseconds.
            $checkpointKey = self::cityCheckpointKey($area);
            if (! $rescore && ! $dryRun && Cache::has($checkpointKey)) {
                $stats['cities_skipped']++;
                continue;
            }

            if ($i > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            // Defensive clamp on top of the SQL guard — an invalid bbox must
            // never reach Overpass.
            $south = max(self::PH_LAT_MIN, (float) $area->min_lat - self::BBOX_PAD);
            $north = min(self::PH_LAT_MAX, (float) $area->max_lat + self::BBOX_PAD);
            $west = max(self::PH_LNG_MIN, (float) $area->min_lng - self::BBOX_PAD);
            $east = min(self::PH_LNG_MAX, (float) $area->max_lng + self::BBOX_PAD);
            if ($south >= $north || $west >= $east) {
                $stats['failed_cities']++;
                $this->warn("  {$area->city}: SKIPPED — degenerate bbox");
                continue;
            }

            try {
                $pois = $overpass->poisInBbox($south, $west, $north, $east);
            } catch (\Throwable $e) {
                // One bad city never aborts the sweep.
                $stats['failed_cities']++;
                $this->warn("  {$area->city}: FAILED — " . Str::limit($e->getMessage(), 140));
                continue;
            }

            $stats['cities']++;
            $cityNew = $cityUpdated = 0;

            foreach ($pois as $poi) {
                $stats['pois']++;

                $existing = FacilityCandidate::query()
                    ->where('source', 'osm')
                    ->where('osm_type', $poi['osm_type'])
                    ->where('osm_id', $poi['osm_id'])
                    ->first();

                // Fresh (scored recently) → skip entirely; the weekly-ish
                // rescan window keeps repeat runs cheap and resumable.
                if ($existing && ! $rescore && $existing->scanned_at?->gt(now()->subDays(self::FRESH_DAYS))) {
                    $stats['fresh_skipped']++;
                    continue;
                }

                // Dedupe against the live registry: slug collision (also
                // covers former_slugs) OR same-category facility within
                // ~250m. Matched candidates are kept for transparency but
                // excluded from the pending queue.
                $slug = Str::slug($poi['name']);
                $matchedId = $this->nearbyFacilityId($registry, $poi['category'], $poi['lat'], $poi['lng']);
                if ($matchedId === null && $slug !== '' && Facility::slugInUse($slug)) {
                    $matchedId = Facility::query()->where('slug', $slug)->value('id') ?? 0;
                    $matchedId = $matchedId ?: null;
                }

                // Score with the exact same radius query as the nightly
                // compute — no preview-vs-nightly drift by construction.
                $preview = $counts->previewCounts($poi['lat'], $poi['lng']);
                if ($preview['clears_floor']) {
                    $stats['clears']++;
                }
                if ($matchedId !== null) {
                    $stats['matched']++;
                }

                if ($dryRun) {
                    continue;
                }

                // Upsert — status deliberately NOT in the update payload so
                // approved/dismissed survive rescans.
                FacilityCandidate::updateOrCreate(
                    ['source' => 'osm', 'osm_type' => $poi['osm_type'], 'osm_id' => $poi['osm_id']],
                    [
                        'name'                => $poi['name'],
                        'category'            => $poi['category'],
                        'lat'                 => $poi['lat'],
                        'lng'                 => $poi['lng'],
                        'city'                => $area->city,
                        'province'            => $area->province,
                        'city_id'             => $area->city_id,
                        'max_total'           => (int) $preview['max_total'],
                        'clears_floor'        => (bool) $preview['clears_floor'],
                        'cohorts'             => $preview['cohorts'],
                        'matched_facility_id' => $matchedId,
                        'scanned_at'          => now(),
                    ],
                );

                $existing ? $cityUpdated++ : $cityNew++;
            }

            $stats['new'] += $cityNew;
            $stats['updated'] += $cityUpdated;
            $this->line(sprintf('  %s, %s: %d POI(s), %d new, %d updated', $area->city, $area->province, count($pois), $cityNew, $cityUpdated));

            // Only a COMPLETED city checkpoints (failed/degenerate ones hit
            // `continue` above and stay eligible for the next run).
            if (! $dryRun) {
                Cache::put($checkpointKey, now()->toDateTimeString(), now()->addDays(self::FRESH_DAYS));
            }
        }

        $this->info(sprintf(
            '%sScanned %d/%d cities (%d checkpoint-skipped, %d failed): %d POIs seen, %d new + %d updated candidates, %d fresh-skipped, %d matched existing, %d clear the ≥%d floor.',
            $dryRun ? '[DRY RUN] ' : '',
            $stats['cities'],
            $cities->count(),
            $stats['cities_skipped'],
            $stats['failed_cities'],
            $stats['pois'],
            $stats['new'],
            $stats['updated'],
            $stats['fresh_skipped'],
            $stats['matched'],
            $stats['clears'],
            ComputeFacilityCounts::MIN_LISTINGS,
        ));

        if ($budgetHit) {
            $remaining = $cities->count() - $stats['cities'] - $stats['cities_skipped'] - $stats['failed_cities'];
            $this->info(sprintf(
                'Time budget (%ds) reached — %d city/ies remaining. Re-run to continue; completed cities are checkpointed for %d days and skip instantly.',
                $budgetSeconds,
                max(0, $remaining),
                self::FRESH_DAYS,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Cache key marking a city as fully scanned this FRESH_DAYS window.
     * Relational id when the registry knows the city; slug fallback keeps
     * text-only cities checkpointable too.
     */
    private static function cityCheckpointKey(object $area): string
    {
        $key = $area->city_id ?: Str::slug(($area->city ?? '') . '-' . ($area->province ?? ''));

        return "seo:facility-scan:city:{$key}";
    }

    /**
     * Scan areas = per-city listing-coordinate extents, biggest inventory
     * first (so a truncated run spends its budget where pages can actually
     * go live). Mirrors the effective-city join used by the sitemap counts
     * (COALESCE(geo pin city, agent-picked barangay's city)) over the
     * publicly-listed + active-agent predicate, with the standard
     * geo_coordinates null/empty guard so blank pins can't poison a bbox.
     */
    private function scanAreas(?int $limit)
    {
        $latExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
        $lngExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(properties.geo_coordinates, '$.lng')) AS DECIMAL(12,8))";

        // Shared base: publicly-listed + active-agent + effective-city join +
        // geo guard + PH junk-pin bounds (see PH_* consts — swapped lat/lng
        // and 0,0 pins must never touch a bbox OR the centroid).
        $base = fn () => DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('agents', function ($j) {
                $j->on('agents.id', '=', 'listings.agent_id')
                  ->where('agents.status', 'active')
                  ->whereNull('agents.deleted_at');
            })
            ->leftJoin('barangays as addr_b', 'addr_b.id', '=', 'properties.address_id')
            ->join('cities', 'cities.id', '=', DB::raw('COALESCE(properties.geo_city_id, addr_b.city_id)'))
            ->whereNull('listings.deleted_at')
            ->whereNull('properties.deleted_at')
            ->where('listings.visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('listings.verification_status')
                  ->orWhere('listings.verification_status', '!=', 'flagged');
            })
            ->whereNotNull('properties.geo_coordinates')
            ->where('properties.geo_coordinates', '!=', '')
            ->whereRaw("$latExpr BETWEEN ? AND ?", [self::PH_LAT_MIN, self::PH_LAT_MAX])
            ->whereRaw("$lngExpr BETWEEN ? AND ?", [self::PH_LNG_MIN, self::PH_LNG_MAX]);

        // Per-city listing centroid — the anchor for outlier trimming.
        $centroids = $base()
            ->select('cities.id as city_id')
            ->selectRaw("AVG($latExpr) as avg_lat")
            ->selectRaw("AVG($lngExpr) as avg_lng")
            ->groupBy('cities.id');

        // Extents over centroid-trimmed pins only (see TRIM_DEG).
        return $base()
            ->join('provinces', 'provinces.id', '=', 'cities.province_id')
            ->joinSub($centroids, 'ctr', fn ($j) => $j->on('ctr.city_id', '=', 'cities.id'))
            ->whereRaw("$latExpr BETWEEN ctr.avg_lat - ? AND ctr.avg_lat + ?", [self::TRIM_DEG, self::TRIM_DEG])
            ->whereRaw("$lngExpr BETWEEN ctr.avg_lng - ? AND ctr.avg_lng + ?", [self::TRIM_DEG, self::TRIM_DEG])
            ->select(
                'cities.id as city_id',
                'cities.name as city',
                'provinces.name as province',
                DB::raw("MIN($latExpr) as min_lat"),
                DB::raw("MAX($latExpr) as max_lat"),
                DB::raw("MIN($lngExpr) as min_lng"),
                DB::raw("MAX($lngExpr) as max_lng"),
                DB::raw('COUNT(*) as cnt'),
            )
            ->groupBy('cities.id', 'cities.name', 'provinces.name')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_CITY_LISTINGS])
            ->orderByDesc('cnt')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();
    }

    /** Id of an existing same-category facility within MATCH_RADIUS_KM, else null. */
    private function nearbyFacilityId(array $registry, string $category, float $lat, float $lng): ?int
    {
        foreach ($registry as $f) {
            if ($f->category !== $category || $f->lat === null || $f->lng === null) {
                continue;
            }
            $dLat = deg2rad((float) $f->lat - $lat);
            $dLng = deg2rad((float) $f->lng - $lng);
            $a = sin($dLat / 2) ** 2
                + cos(deg2rad($lat)) * cos(deg2rad((float) $f->lat)) * sin($dLng / 2) ** 2;
            $km = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
            if ($km <= self::MATCH_RADIUS_KM) {
                return (int) $f->id;
            }
        }

        return null;
    }
}
