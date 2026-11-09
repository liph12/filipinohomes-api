<?php

namespace App\Services\Listing;

use Illuminate\Support\Facades\DB;

/**
 * The development a typed name means ("Horizon 101", "Mivesa", "Avida Riala") → its `projects` row.
 * Shared by the project profile and the listing search, so "how many units are in Horizon 101" and
 * "show me the units in Horizon 101" look at the same building.
 */
class ProjectFinder
{
    public function __construct(private readonly PriceGuideService $guide)
    {
    }

    /**
     * Every word of the name within the project's name, the most viewed first (a city hint breaks
     * ties towards that city). Null for a name that is really a city or barangay — "listings in
     * Lahug" must never be pinned to a development with Lahug in its name — or that matches nothing.
     */
    public function find(string $name, string $cityHint = ''): ?object
    {
        $words = array_values(array_filter(preg_split('/\W+/u', mb_strtolower($name)) ?: [], fn ($w) => mb_strlen($w) > 1));
        if ($words === []) {
            return null;
        }
        try {
            if ($this->guide->placeOf($name) !== null) {
                return null;
            }
        } catch (\Throwable) {
        }
        $q = DB::table('projects')->whereNull('deleted_at');
        foreach ($words as $w) {
            $q->where('name', 'LIKE', "%{$w}%");
        }
        if ($cityHint !== '') {
            $q->orderByRaw('CASE WHEN complete_address LIKE ? THEN 0 ELSE 1 END', ['%'.$cityHint.'%']);
        }

        return $q->orderByDesc('views')->first(['id', 'name', 'slug', 'complete_address', 'latitude', 'longitude', 'featured_photo', 'views', 'city_id', 'brgy_id']);
    }
}
