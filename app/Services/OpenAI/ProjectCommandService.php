<?php

namespace App\Services\OpenAI;

use App\Services\Listing\PriceGuideService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ASA-GPT's "tell me about Avida Towers Riala" — a development profiled from the listings inside it,
 * and "Riala vs 38 Park Avenue" — two profiled side by side.
 *
 * The model only EXTRACTS the names (CommandService::extractProjects); the development is resolved
 * in our own `projects` table and every figure comes from the live public listings whose property
 * belongs to it: how many for sale and for rent, typical price and ₱/sqm per unit size, typical
 * rent, gross yield like for like, and who lists there. Streams a panel like the other reports.
 */
class ProjectCommandService extends CacheService
{
    use InsightStream;

    public function __construct(private readonly CommandService $commands, private readonly PriceGuideService $guide)
    {
    }

    public function replyStream(array $thread, $req): StreamedResponse
    {
        $asked = $this->commands->extractProjects($thread);
        $profiles = [];
        $missing = [];
        foreach ($asked['projects'] as $name) {
            $p = $this->resolve($name, $asked['city']);
            if ($p === null) {
                $missing[] = $name;
                continue;
            }
            $profile = $this->profile((int) $p->id);
            if ($profile !== null) {
                $profiles[] = $profile;
            } else {
                $missing[] = $name;
            }
        }

        if ($profiles === []) {
            $named = $missing !== [] ? ' **'.implode('** or **', $missing).'**' : ' that development';
            $text = "I can't find{$named} among the developments with live listings on Filipino Homes. Check the spelling, or name the city it is in.";

            return $this->streamInsight('project', 'project', ['empty' => true], $text, [], $req);
        }

        $names = array_map(fn (array $p) => $p['name'], $profiles);
        $text = count($profiles) > 1
            ? sprintf('**%s** side by side, from the live listings in each.', implode('** vs **', $names))
            : sprintf('**%s** at a glance, from %d live listings in the development.', $names[0], $profiles[0]['totals']['listings']);
        if ($missing !== []) {
            $text .= ' I could not find **'.implode('**, **', $missing).'**.';
        }
        $first = $profiles[0];
        $followUps = array_values(array_filter([
            ['label' => "Listings in {$first['name']}", 'prompt' => "Show me units for sale in {$first['name']}"],
            $first['barangay'] ? ['label' => "About {$first['barangay']}", 'prompt' => "Tell me about {$first['barangay']}, {$first['city']} as an area"] : null,
            ['label' => 'Agents who list here', 'prompt' => "Find me the best agent for {$first['name']}"],
        ]));

        return $this->streamInsight('project', 'project', ['projects' => $profiles, 'compare' => count($profiles) > 1], $text, $followUps, $req);
    }

    /** The project row a typed name points at — the best-known match with live listings. */
    public function resolve(string $name, string $cityHint = ''): ?object
    {
        $words = array_values(array_filter(preg_split('/\W+/u', mb_strtolower($name)), fn ($w) => mb_strlen($w) > 1));
        if ($words === []) {
            return null;
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

    /**
     * @return array<string, mixed>|null
     */
    public function profile(int $projectId): ?array
    {
        return Cache::remember("project-insight:v1:{$projectId}", 1800, function () use ($projectId) {
            $p = DB::table('projects')->where('id', $projectId)->first();
            if ($p === null) {
                return null;
            }
            $rows = DB::table('listings')
                ->join('properties', 'properties.id', '=', 'listings.property_id')
                ->join('agents', fn ($j) => $j->on('agents.id', '=', 'listings.agent_id')->where('agents.status', 'active')->whereNull('agents.deleted_at'))
                ->join('categories', 'categories.id', '=', 'listings.category_id')
                ->join('property_attributes', 'property_attributes.id', '=', 'properties.property_attribute_id')
                ->join('property_subtypes', 'property_subtypes.id', '=', 'property_attributes.property_subtype_id')
                ->join('property_types', 'property_types.id', '=', 'property_subtypes.property_type_id')
                ->where('properties.project_id', $projectId)
                ->whereNull('listings.deleted_at')->whereNull('properties.deleted_at')
                ->where('listings.visibility', 'public')
                ->where(fn ($q) => $q->whereNull('listings.verification_status')->orWhere('listings.verification_status', '!=', 'flagged'))
                ->whereNotNull('listings.price')
                // The Price Guide's floors: a ₱1 placeholder is not a price.
                ->where(fn ($q) => $q->where(fn ($s) => $s->where('categories.name', 'For Sale')->where('listings.price', '>=', 100000))
                    ->orWhere(fn ($s) => $s->where('categories.name', 'For Rent')->where('listings.price', '>=', 1000)))
                ->select('listings.id', 'listings.price', 'listings.created_at', 'categories.name as category', 'property_types.name as type', 'property_subtypes.name as subtype', 'property_attributes.bedroom_count', 'property_attributes.floor_area', 'agents.id as agent_id', 'agents.first_name', 'agents.last_name')
                ->get();
            if ($rows->isEmpty()) {
                return null;
            }

            $slim = $rows->map(function ($r) {
                $price = (float) $r->price;
                $area = (float) ($r->floor_area ?? 0);

                return [
                    'category' => (string) $r->category,
                    'type' => (string) $r->type,
                    'tier' => PriceGuideService::tierOf((string) $r->subtype, $r->bedroom_count === null ? null : (int) $r->bedroom_count),
                    'price' => $price,
                    'ppsqm' => $area >= 10 ? $price / $area : null,
                    'agent' => ['id' => (int) $r->agent_id, 'name' => trim("{$r->first_name} {$r->last_name}")],
                    'created_at' => (string) $r->created_at,
                ];
            })->all();

            $stats = function (array $set): ?array {
                if (count($set) < 2) {
                    return null;
                }
                $prices = array_column($set, 'price');
                sort($prices);
                $pp = array_values(array_filter(array_column($set, 'ppsqm')));
                sort($pp);
                $pct = function (array $sorted, float $q) {
                    $n = count($sorted);
                    if ($n === 1) {
                        return (float) $sorted[0];
                    }
                    $pos = ($n - 1) * $q;
                    $lo = (int) floor($pos);
                    $hi = (int) ceil($pos);

                    return (float) $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
                };

                return ['listings' => count($prices), 'low' => round($pct($prices, 0.25)), 'median' => round($pct($prices, 0.5)), 'high' => round($pct($prices, 0.75)), 'median_ppsqm' => $pp === [] ? null : round($pct($pp, 0.5))];
            };

            $sections = [];
            foreach (['For Sale', 'For Rent'] as $cat) {
                $in = array_values(array_filter($slim, fn ($r) => $r['category'] === $cat));
                if ($in === []) {
                    continue;
                }
                $all = $stats($in);
                $tiers = [];
                foreach (array_merge(PriceGuideService::tierOrder(), [null]) as $tier) {
                    $t = array_values(array_filter($in, fn ($r) => $r['tier'] === $tier));
                    $st = $stats($t);
                    if ($st !== null) {
                        $tiers[] = ['tier' => $tier ?? 'Other', ...$st];
                    }
                }
                $sections[] = ['category' => $cat, 'listings' => count($in), 'low' => $all['low'] ?? null, 'median' => $all['median'] ?? null, 'high' => $all['high'] ?? null, 'median_ppsqm' => $all['median_ppsqm'] ?? null, 'tiers' => $tiers];
            }

            // Gross yield like for like: per unit size with both sides present, combined by median.
            $yields = [];
            foreach (PriceGuideService::tierOrder() as $tier) {
                $sale = $stats(array_values(array_filter($slim, fn ($r) => $r['category'] === 'For Sale' && $r['tier'] === $tier)));
                $rentS = $stats(array_values(array_filter($slim, fn ($r) => $r['category'] === 'For Rent' && $r['tier'] === $tier)));
                if ($sale && $rentS && $sale['median'] > 0) {
                    $y = $rentS['median'] * 12 / $sale['median'] * 100;
                    if ($y >= 1 && $y <= 15) {
                        $yields[] = ['tier' => $tier, 'yield_pct' => round($y, 1), 'sale_median' => $sale['median'], 'rent_median' => $rentS['median']];
                    }
                }
            }
            usort($yields, fn ($a, $b) => $a['yield_pct'] <=> $b['yield_pct']);
            $yield = $yields === [] ? null : $yields[intdiv(count($yields), 2)];

            $byAgent = [];
            foreach ($slim as $r) {
                $byAgent[$r['agent']['id']] ??= ['id' => $r['agent']['id'], 'name' => $r['agent']['name'], 'listings' => 0];
                $byAgent[$r['agent']['id']]['listings']++;
            }
            usort($byAgent, fn ($a, $b) => $b['listings'] <=> $a['listings']);

            $photo = json_decode((string) ($p->featured_photo ?? ''), true);
            $city = $p->city_id ? DB::table('cities')->where('id', $p->city_id)->value('name') : null;
            $brgy = $p->brgy_id ? DB::table('barangays')->where('id', $p->brgy_id)->value('name') : null;
            $types = array_count_values(array_column($slim, 'type'));
            arsort($types);
            $newest = max(array_column($slim, 'created_at'));

            return [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'slug' => (string) $p->slug,
                'address' => (string) ($p->complete_address ?? ''),
                'city' => $city,
                'barangay' => $brgy,
                'lat' => is_numeric($p->latitude) ? (float) $p->latitude : null,
                'lng' => is_numeric($p->longitude) ? (float) $p->longitude : null,
                'photo' => is_array($photo) ? ($photo[0] ?? null) : null,
                'type' => array_key_first($types),
                'views' => (int) ($p->views ?? 0),
                'totals' => [
                    'listings' => count($slim),
                    'sale_listings' => count(array_filter($slim, fn ($r) => $r['category'] === 'For Sale')),
                    'rent_listings' => count(array_filter($slim, fn ($r) => $r['category'] === 'For Rent')),
                    'agents' => count($byAgent),
                ],
                'sections' => $sections,
                'yield' => $yield,
                'agents' => array_slice($byAgent, 0, 3),
                'newest_listing_at' => $newest,
            ];
        });
    }
}
