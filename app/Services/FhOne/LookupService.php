<?php

namespace App\Services\FhOne;

use App\Services\Reports\Executive\Geography;
use App\Services\Reports\Executive\TeamDirectory;
use App\Support\OfficeRegionMap;
use Illuminate\Support\Facades\DB;

/**
 * Turns a name into the id a filter needs ("Alpha" → team_id 3), so FH One
 * can resolve what a person typed before it asks for a number. At most 20
 * matches, names that start with the text first.
 */
final class LookupService
{
    /** Lookup type → the filter its ids belong to. */
    public const TYPES = [
        'teams' => 'team_id',
        'projects' => 'project_id',
        'regions' => 'office_region',
        'provinces' => 'province_id',
        'cities' => 'city_id',
        'types' => 'type_id',
    ];

    public const LIMIT = 20;

    /** Places and projects are many; one letter would match half of them. */
    public const MIN_CHARS = 2;

    public function __construct(
        private readonly TeamDirectory $teams,
        private readonly Geography $geo,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, truncated: bool}
     */
    public function search(string $type, string $text): array
    {
        $needle = mb_strtolower(trim($text));

        if (in_array($type, ['projects', 'cities'], true) && mb_strlen($needle) < self::MIN_CHARS) {
            return ['data' => [], 'truncated' => false];
        }

        if ($type === 'projects') {
            return $this->projects($needle);
        }

        return $this->rank(match ($type) {
            'teams' => $this->teamItems(),
            'regions' => $this->regionItems(),
            'provinces' => $this->provinceItems(),
            'cities' => $this->cityItems(),
            'types' => $this->typeItems(),
        }, $needle);
    }

    // ── Sources ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function teamItems(): array
    {
        $items = [];
        foreach ($this->teams->teams() as $id => $name) {
            $items[] = ['id' => (int) $id, 'label' => $name, 'agents' => count($this->teams->members((int) $id))];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function regionItems(): array
    {
        return array_map(
            fn (string $region) => ['id' => $region, 'label' => OfficeRegionMap::label($region)],
            [...OfficeRegionMap::REGIONS, 'unassigned'],
        );
    }

    /** @return list<array<string, mixed>> */
    private function provinceItems(): array
    {
        $items = [];
        foreach ($this->geo->provinceNames() as $id => $name) {
            // A province that is the same place as another is reported under one id.
            if ($this->geo->canonicalProvince((int) $id) === (int) $id) {
                $items[] = ['id' => (int) $id, 'label' => $name];
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function cityItems(): array
    {
        $items = [];
        foreach ($this->geo->townNames() as $group => $name) {
            $province = $this->geo->provinceName($this->geo->cityProvince((int) $group));
            $items[] = [
                'id' => (int) $group,
                'label' => $province !== null ? "{$name}, {$province}" : $name,
                'name' => $name,
                'province' => $province,
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function typeItems(): array
    {
        return DB::table('property_types')->orderBy('name')->get(['id', 'name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'label' => (string) $row->name])->all();
    }

    /** @return array{data: list<array<string, mixed>>, truncated: bool} */
    private function projects(string $needle): array
    {
        $like = '%'.addcslashes($needle, '%_\\').'%';

        $rows = DB::table('projects')
            ->whereNull('deleted_at')
            ->whereNotNull('name')
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            // Names that start with the text first, then alphabetical.
            ->orderByRaw('CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END', [addcslashes($needle, '%_\\').'%'])
            ->orderBy('name')
            ->limit(self::LIMIT + 1)
            ->get(['id', 'name', 'city_id']);

        $items = $rows->take(self::LIMIT)->map(function ($row) {
            $place = $row->city_id === null ? null : $this->geo->cityName($this->geo->cityGroup((int) $row->city_id));

            return ['id' => (int) $row->id, 'label' => (string) $row->name, 'place' => $place];
        })->values()->all();

        return ['data' => $items, 'truncated' => $rows->count() > self::LIMIT];
    }

    // ── Matching ────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{data: list<array<string, mixed>>, truncated: bool}
     */
    private function rank(array $items, string $needle): array
    {
        if ($needle !== '') {
            $items = array_values(array_filter($items, fn (array $item) => str_contains(mb_strtolower($item['label']), $needle)));
        }

        usort($items, function (array $a, array $b) use ($needle) {
            $left = mb_strtolower($a['label']);
            $right = mb_strtolower($b['label']);

            return [! str_starts_with($left, $needle), $left] <=> [! str_starts_with($right, $needle), $right];
        });

        return ['data' => array_slice($items, 0, self::LIMIT), 'truncated' => count($items) > self::LIMIT];
    }
}
