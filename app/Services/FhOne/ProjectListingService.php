<?php

namespace App\Services\FhOne;

use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\ReportQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Projects that have listings, for GET /api/fh-one/v1/projects — searchable
 * by name and paged like a metric's records.
 *
 * Counted on the report's own listing chain (ReportQueries::listingBase():
 * nothing deleted, standard categories, project linked through
 * properties.project_id), so a project's `listings` here is the same set the
 * metrics see under `project_id`, and `live` is listings.live's rule (public
 * and active). The `id` is the project_id filter value.
 */
final class ProjectListingService
{
    public const MAX_PER_PAGE = 200;

    /** Sort name → SQL expression. */
    public const SORTS = [
        'listings' => 'listings',
        'live' => 'live',
        'name' => 'projects.name',
    ];

    public function __construct(
        private readonly ReportQueries $queries,
        private readonly Geography $geo,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, sort: array{by: string, dir: string}, meta: array<string, int>}
     */
    public function list(string $search, bool $liveOnly, int $page, int $perPage, string $sort, string $dir): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);

        $grouped = $this->grouped();

        $needle = mb_strtolower(trim($search));
        if ($needle !== '') {
            // Bound, with LIKE's wildcards escaped: a search is text, never SQL.
            $grouped->whereRaw('LOWER(projects.name) LIKE ?', ['%'.addcslashes($needle, '%_\\').'%']);
        }
        if ($liveOnly) {
            $grouped->havingRaw("COUNT(DISTINCT CASE WHEN listings.visibility = 'public' AND properties.status = 'active' THEN listings.id END) > 0");
        }

        $total = (int) DB::query()->fromSub($grouped, 'p')->count();

        $rows = DB::query()->fromSub($grouped, 'projects')
            ->orderBy(self::SORTS[$sort], $dir)
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->forPage($page, $perPage)
            ->get();

        return [
            'rows' => $rows->map(fn ($row) => $this->row($row))->values()->all(),
            'sort' => ['by' => $sort, 'dir' => $dir],
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * One project with its counts, or null when it doesn't exist or has no
     * listings (so it isn't in list() either).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->grouped()->where('projects.id', $id)->first();

        return $row === null ? null : $this->row($row);
    }

    /** Projects on the report's listing chain, one row each with its counts. */
    private function grouped(): Builder
    {
        return $this->queries->listingBase()
            ->whereNotNull('projects.id')
            ->groupBy('projects.id', 'projects.name', 'projects.slug', 'projects.city_id')
            ->select([
                'projects.id', 'projects.name', 'projects.slug', 'projects.city_id',
                DB::raw('COUNT(DISTINCT listings.id) as listings'),
                DB::raw("COUNT(DISTINCT CASE WHEN listings.visibility = 'public' AND properties.status = 'active' THEN listings.id END) as live"),
            ]);
    }

    /** @return array<string, mixed> */
    private function row(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => trim((string) preg_replace('/\s+/', ' ', (string) $row->name)),
            'slug' => $row->slug === null ? null : (string) $row->slug,
            'place' => $row->city_id === null ? null : $this->geo->cityName($this->geo->cityGroup((int) $row->city_id)),
            'listings' => (int) $row->listings,
            'live' => (int) $row->live,
        ];
    }
}
