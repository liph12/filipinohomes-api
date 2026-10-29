<?php

namespace App\Services\Listing;

use App\Models\NearbyFacility;
use App\Services\OpenAI\LandmarkLocator;
use Illuminate\Support\Facades\Cache;

/**
 * Every school, hospital, clinic, pharmacy, mall, park, church, transport stop, police and fire
 * station that any listing's agent ever saved (nearby_facilities: 863 rows, ~39k entries), as one
 * place index: the places are real points on the map, so a listing with no saved row of its own
 * can still be measured against them. Grid-bucketed by 0.05° (about 5 km) so "nearest mall to this
 * pin" scans nine cells, not the whole country; cleaned the way the listing page cleans them, with
 * placeholders and off-category names dropped. Rebuilt from the table every six hours.
 */
class FacilityIndex
{
    public const CATEGORIES = ['school', 'hospital', 'clinic', 'pharmacy', 'mall', 'park', 'church', 'transport_station', 'police_station', 'fire_station', 'attraction'];

    /** The word a card or a sentence uses for one of them. */
    public const SINGULAR = [
        'school' => 'school',
        'hospital' => 'hospital',
        'clinic' => 'clinic',
        'pharmacy' => 'pharmacy',
        'mall' => 'mall',
        'park' => 'park',
        'church' => 'church',
        'transport_station' => 'transport stop',
        'police_station' => 'police station',
        'fire_station' => 'fire station',
        'attraction' => 'place to see',
    ];

    private const CELL = 0.05;

    /**
     * A "park", "hospital" or "school" from Google's nearby search must look like one by name; the
     * rest is a shop, a street or a bare place name ("Talisay" filed as a school at the property's
     * own coordinates). A school may also pass on a long proper name of three words or more.
     */
    private const MUST_LOOK_LIKE = [
        'park' => '/\b(park|plaza|playground|garden|nature|eco|reserve|boulevard|baywalk|green|field|oval|sports|court|complex|grounds|promenade|walk|lagoon)\b/i',
        'hospital' => '/hosp|medic|clinic|health|infirm|center|centre|doctor|dental|diagnost|lab|maternity|lying-in|ospital/i',
        'school' => '/school|academy|college|universit|institute|elementary|kinder|montessori|learning|preschool|daycare|seminary|\bhigh\b|\bprep\b|\bibed\b|\bsti\b|campus|education|tutorial|eskwela|paaralan/i',
    ];

    /** @var array<string, array<string, array<int, array{0: string, 1: float, 2: float}>>>|null category → cell → [name, lat, lng] */
    private ?array $index = null;

    /** A saved place worth naming: not Google's "Unnamed police_station", and in its category by name. */
    public static function looksReal(string $category, string $name): bool
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^unnamed\b/i', $name)) {
            return false;
        }

        if (! isset(self::MUST_LOOK_LIKE[$category]) || preg_match(self::MUST_LOOK_LIKE[$category], $name) === 1) {
            return true;
        }

        return $category === 'school' && str_word_count($name) >= 3;
    }

    /**
     * The nearest saved place of a category within $withinM of the point.
     *
     * @return array{name: string, lat: float, lng: float, meters: int}|null
     */
    public function nearest(string $category, float $lat, float $lng, int $withinM = 3000): ?array
    {
        $best = null;
        foreach ($this->candidates($category, $lat, $lng) as [$name, $pLat, $pLng]) {
            $m = NearbyPlacesFinder::metres($lat, $lng, $pLat, $pLng);
            if ($m <= $withinM && ($best === null || $m < $best['meters'])) {
                $best = ['name' => $name, 'lat' => $pLat, 'lng' => $pLng, 'meters' => (int) round($m)];
            }
        }

        return $best;
    }

    /**
     * Every saved place within $withinM of the point, nearest first, at most $perCategory per
     * category, in the `category => [{name, address, lat, lng, distance_meters}]` shape the stored
     * rows use.
     *
     * @return array<string, array<int, array{name: string, address: string, lat: float, lng: float, distance_meters: int}>>
     */
    public function around(float $lat, float $lng, int $withinM = 3000, int $perCategory = 4): array
    {
        $out = [];
        foreach (self::CATEGORIES as $category) {
            $items = [];
            foreach ($this->candidates($category, $lat, $lng) as [$name, $pLat, $pLng]) {
                $m = NearbyPlacesFinder::metres($lat, $lng, $pLat, $pLng);
                if ($m <= $withinM) {
                    $items[] = ['name' => $name, 'address' => '', 'lat' => $pLat, 'lng' => $pLng, 'distance_meters' => (int) round($m)];
                }
            }
            if ($items === []) {
                continue;
            }
            usort($items, fn ($a, $b) => $a['distance_meters'] <=> $b['distance_meters']);
            $out[$category] = array_slice($items, 0, $perCategory);
        }

        return $out;
    }

    /** @return array<int, array{0: string, 1: float, 2: float}> */
    private function candidates(string $category, float $lat, float $lng): array
    {
        $cells = $this->index()[$category] ?? [];
        if ($cells === []) {
            return [];
        }
        $out = [];
        $la = (int) floor($lat / self::CELL);
        $lo = (int) floor($lng / self::CELL);
        for ($i = -1; $i <= 1; $i++) {
            for ($j = -1; $j <= 1; $j++) {
                foreach ($cells[($la + $i).','.($lo + $j)] ?? [] as $entry) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<string, array<int, array{0: string, 1: float, 2: float}>>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $this->index = Cache::remember('facility_index:v2', 6 * 3600, fn () => self::build());

        return $this->index;
    }

    /** @return array<string, array<string, array<int, array{0: string, 1: float, 2: float}>>> */
    private static function build(): array
    {
        $index = [];
        $seen = [];
        NearbyFacility::query()->orderBy('id')->chunk(200, function ($rows) use (&$index, &$seen) {
            foreach ($rows as $row) {
                $cleaned = NearbyFacilityCleaner::clean(
                    collect(NearbyFacilityCleaner::CATEGORIES)->mapWithKeys(fn ($c) => [$c => $row->{$c} ?? []])->all()
                );
                foreach ($cleaned as $category => $items) {
                    foreach ($items as $p) {
                        $name = trim((string) ($p['name'] ?? ''));
                        if (! isset($p['lat'], $p['lng']) || ! is_numeric($p['lat']) || ! is_numeric($p['lng']) || ! self::looksReal($category, $name)) {
                            continue;
                        }
                        $point = LandmarkLocator::philippinePoint((float) $p['lat'], (float) $p['lng']);
                        if ($point === null) {
                            continue;
                        }
                        $key = $category.'|'.mb_strtolower($name).'|'.round($point['lat'], 3).','.round($point['lng'], 3);
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $cell = ((int) floor($point['lat'] / self::CELL)).','.((int) floor($point['lng'] / self::CELL));
                        $index[$category][$cell][] = [$name, round($point['lat'], 5), round($point['lng'], 5)];
                    }
                }
            }
        });

        return $index;
    }
}
