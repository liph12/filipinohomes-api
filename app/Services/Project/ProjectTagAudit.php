<?php

namespace App\Services\Project;

use App\Models\ProjectTagDismissal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Listings whose title names a development they are not tagged to.
 *
 * An agent who writes "Corona Del Mar Lot" but never picks the project leaves the listing out of the
 * project's page and out of ASA-GPT's counts. This finds those: every live public listing is read
 * against every project name (longest name first, a developer brand that merely starts many names is
 * skipped, "near X" is not "in X"), and a listing is flagged when it names a project and carries no
 * tag, or a tag to a project the title does not agree with. Pure matching is static so it can be tested
 * without a database; the report itself is cached for ten minutes and forgotten on every fix.
 */
class ProjectTagAudit
{
    public const CACHE_KEY = 'project-tag-audit:v1';

    public const TTL = 600;

    /** Words that name a kind of place, not a particular one. */
    private const GENERIC = ['residences', 'residence', 'tower', 'towers', 'condo', 'condos', 'condominium', 'condominiums', 'homes', 'home', 'village', 'subdivision', 'city', 'park', 'place', 'heights', 'garden', 'gardens', 'estates', 'estate', 'the', 'at', 'de', 'del', 'la', 'las', 'los', 'san', 'santa', 'sta', 'sto', 'santo', 'plaza', 'square', 'suites', 'hotel', 'phase', 'block', 'lot', 'house', 'apartment', 'apartments', 'building', 'bldg', 'center', 'centre', 'mall', 'point', 'view', 'views', 'hills', 'hill', 'ridge', 'grove', 'court', 'courts', 'manor', 'manors', 'terrace', 'terraces', 'villas', 'villa', 'one', 'two', 'north', 'south', 'east', 'west', 'new', 'royal', 'grand', 'premier', 'prime'];

    /** "Avida Tower Riala" and "Avida Towers Riala" are one name. */
    private const PLURAL = ['towers' => 'tower', 'residences' => 'residence', 'gardens' => 'garden', 'homes' => 'home', 'villas' => 'villa', 'suites' => 'suite', 'estates' => 'estate', 'heights' => 'height', 'hills' => 'hill', 'courts' => 'court', 'terraces' => 'terrace', 'condos' => 'condo', 'apartments' => 'apartment', 'manors' => 'manor', 'groves' => 'grove', 'parks' => 'park', 'places' => 'place', 'views' => 'view', 'points' => 'point'];

    /** Said just before a project's name, these make it a neighbour, not the address. */
    private const NEAR = '/\b(near|beside|along|across|fronting|in ?front|walking distance|minutes?|mins?|close to|behind|next to|steps?|infront|malapit|tapad|atbang|nearby|accessible|adjacent)\b/u';

    /** Project rows that are really a listing title typed into the projects table. */
    private const JUNK = '/^(for (sale|rent|lease)|rush sale|assume|pre ?selling|ready for occupancy|rfo|brand new)\b/u';

    // ── The report ────────────────────────────────────────────────────────

    /** @return array{summary: array<string, mixed>, rows: array<int, array<string, mixed>>} */
    public function report(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL, fn () => $this->compute());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** The development a piece of text (a title, a property name, an address) names, for the listing form. */
    public function detect(string $text): ?array
    {
        $index = $this->index();
        $hit = self::namedIn($text, $index);
        if ($hit === null || $hit['near']) {
            return null;
        }

        return ['id' => $hit['id'], 'name' => $index['projects'][$hit['id']]['name']];
    }

    /** @return array<string, mixed> */
    private function index(): array
    {
        return Cache::remember(self::CACHE_KEY.':index', self::TTL, function () {
            $projects = DB::table('projects')->whereNull('deleted_at')->get(['id', 'name', 'slug'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'slug' => (string) $p->slug])->all();

            return self::buildIndex($projects);
        });
    }

    /** @return array{summary: array<string, mixed>, rows: array<int, array<string, mixed>>} */
    private function compute(): array
    {
        $index = $this->index();
        $projects = $index['projects'];
        $listings = DB::table('listings')
            ->join('properties', 'properties.id', '=', 'listings.property_id')
            ->join('agents', fn ($j) => $j->on('agents.id', '=', 'listings.agent_id')->where('agents.status', 'active')->whereNull('agents.deleted_at'))
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereNull('listings.deleted_at')->whereNull('properties.deleted_at')
            ->where('listings.visibility', 'public')
            ->where(fn ($q) => $q->whereNull('listings.verification_status')->orWhere('listings.verification_status', '!=', 'flagged'))
            ->orderBy('listings.id')
            ->get(['listings.id', 'listings.name as title', 'listings.slug', 'listings.price', 'listings.featured_photo', 'listings.created_at', 'categories.name as category', 'properties.id as property_id', 'properties.name as property_name', 'properties.address', 'properties.project_id', 'properties.is_project', 'agents.id as agent_id', 'agents.first_name', 'agents.last_name']);
        $dismissed = [];
        foreach (ProjectTagDismissal::query()->get(['listing_id', 'project_id']) as $d) {
            $dismissed["{$d->listing_id}:{$d->project_id}"] = true;
        }

        $tagged = [];
        $rows = [];
        foreach ($listings as $l) {
            $current = $l->project_id ? (int) $l->project_id : null;
            if ($current !== null) {
                $tagged[$current] = ($tagged[$current] ?? 0) + 1;
            }
            $flag = self::flag((string) $l->title, (string) ($l->property_name ?? ''), $current, $index);
            if ($flag === null || isset($dismissed["{$l->id}:{$flag['project_id']}"])) {
                continue;
            }
            $photos = is_string($l->featured_photo) ? json_decode($l->featured_photo, true) : $l->featured_photo;
            $rows[] = [
                'listing_id' => (int) $l->id,
                'title' => (string) $l->title,
                'slug' => (string) $l->slug,
                'price' => $l->price === null ? null : (float) $l->price,
                'category' => (string) $l->category,
                'photo' => is_array($photos) ? ($photos[0] ?? null) : null,
                'listed_at' => (string) $l->created_at,
                'agent' => ['id' => (int) $l->agent_id, 'name' => trim("{$l->first_name} {$l->last_name}")],
                'property_id' => (int) $l->property_id,
                'is_project' => (bool) $l->is_project,
                'address' => (string) ($l->address ?? ''),
                'named_project' => ['id' => $flag['project_id'], 'name' => $projects[$flag['project_id']]['name'], 'slug' => $projects[$flag['project_id']]['slug']],
                'named_in' => $flag['where'],
                'current_project' => $current !== null && isset($projects[$current]) ? ['id' => $current, 'name' => $projects[$current]['name']] : null,
                'status' => $flag['status'],
            ];
        }

        $byProject = [];
        foreach ($rows as $r) {
            $id = $r['named_project']['id'];
            $byProject[$id] ??= ['id' => $id, 'name' => $r['named_project']['name'], 'tagged' => $tagged[$id] ?? 0, 'not_tagged' => 0, 'mismatch' => 0];
            $byProject[$id][$r['status'] === 'mismatch' ? 'mismatch' : 'not_tagged']++;
        }
        usort($byProject, fn ($a, $b) => ($b['not_tagged'] + $b['mismatch']) <=> ($a['not_tagged'] + $a['mismatch']) ?: strcmp($a['name'], $b['name']));

        return [
            'summary' => [
                'listings_checked' => $listings->count(),
                'tagged_listings' => array_sum($tagged),
                'not_tagged' => count(array_filter($rows, fn ($r) => $r['status'] === 'not_tagged')),
                'mismatch' => count(array_filter($rows, fn ($r) => $r['status'] === 'mismatch')),
                'projects_affected' => count($byProject),
                'agents_affected' => count(array_unique(array_map(fn ($r) => $r['agent']['id'], $rows))),
                'computed_at' => Carbon::now()->toIso8601String(),
            ],
            'by_project' => array_values($byProject),
            'rows' => $rows,
        ];
    }

    // ── Pure matching ─────────────────────────────────────────────────────

    /**
     * The project names as search keys: normalised first-comma piece, longest first, without junk rows,
     * all-generic names and developer brands (a name that merely starts three or more other names).
     *
     * @param  array<int, array{id: int, name: string, slug?: string}>  $projects
     * @return array{projects: array<int, array{id: int, name: string, slug: string}>, keys: array<int, array{0: string, 1: int}>}
     */
    public static function buildIndex(array $projects): array
    {
        $byId = [];
        $keys = [];
        foreach ($projects as $p) {
            $byId[(int) $p['id']] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'slug' => (string) ($p['slug'] ?? '')];
            $first = explode(',', (string) $p['name'])[0];
            $k = self::normalize($first);
            $tokens = $k === '' ? [] : explode(' ', $k);
            if (mb_strlen($k) < 4 || mb_strlen($k) > 60 || $tokens === [] || str_contains($first, ':') || preg_match(self::JUNK, $k)) {
                continue;
            }
            if (count(array_diff($tokens, self::GENERIC)) === 0) {
                continue;
            }
            $keys[] = [$k, (int) $p['id']];
        }
        $all = array_column($keys, 0);
        $keys = array_values(array_filter($keys, function (array $kp) use ($all) {
            $prefix = $kp[0].' ';
            $n = 0;
            foreach ($all as $o) {
                if ($o !== $kp[0] && str_starts_with($o, $prefix) && ++$n >= 3) {
                    return false;
                }
            }

            return true;
        }));
        usort($keys, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return ['projects' => $byId, 'keys' => $keys];
    }

    /**
     * What a listing's words say about its project: null when nothing needs attention, else the project
     * it names, where it was found and whether the tag is missing ('not_tagged') or disagrees ('mismatch').
     *
     * @return array{project_id: int, where: string, status: string}|null
     */
    public static function flag(string $title, string $propertyName, ?int $currentProjectId, array $index): ?array
    {
        $hit = self::namedIn($title, $index);
        $where = 'title';
        if ($hit === null && $propertyName !== '' && self::normalize($propertyName) !== self::normalize($title)) {
            $hit = self::namedIn($propertyName, $index);
            $where = 'property name';
        }
        if ($hit === null) {
            return null;
        }
        $named = $hit['id'];
        if ($currentProjectId === null) {
            return $hit['near'] ? null : ['project_id' => $named, 'where' => $where, 'status' => 'not_tagged'];
        }
        if ($hit['near'] || $currentProjectId === $named || ! isset($index['projects'][$currentProjectId])) {
            return null;
        }
        // The property name mirrors the tag, so only the title can vouch for the current project.
        if (self::family($currentProjectId, $named, $index) || self::agrees($currentProjectId, $title, $index)) {
            return null;
        }

        return ['project_id' => $named, 'where' => $where, 'status' => 'mismatch'];
    }

    /**
     * The longest project name inside the text, with whether the words just before it say "near".
     *
     * @return array{id: int, near: bool}|null
     */
    public static function namedIn(string $text, array $index): ?array
    {
        $h = ' '.self::normalize($text).' ';
        $nearHit = null;
        foreach ($index['keys'] as [$k, $id]) {
            $i = mb_strpos($h, ' '.$k.' ');
            if ($i === false) {
                continue;
            }
            $window = mb_substr($h, max(0, $i - 45), min(45, $i));
            if (preg_match(self::NEAR, $window)) {
                $nearHit ??= $id;
                continue;
            }

            return ['id' => $id, 'near' => false];
        }

        return $nearHit === null ? null : ['id' => $nearHit, 'near' => true];
    }

    /** Lower-case words only, plurals folded: "AVIDA TOWERS RIALA, Cebu" → "avida tower riala cebu". */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(str_replace('&', ' and ', $s));
        $s = preg_replace("/['’]s\\b/u", '', $s) ?? '';
        $s = preg_replace('/[^a-z0-9ñ]+/u', ' ', $s) ?? '';
        $words = array_filter(explode(' ', trim($s)), fn ($w) => $w !== '');

        return implode(' ', array_map(fn ($w) => self::PLURAL[$w] ?? $w, $words));
    }

    /** @return array<int, string> */
    private static function distinctive(int $id, array $index): array
    {
        $words = explode(' ', self::normalize(explode(',', $index['projects'][$id]['name'])[0]));

        return array_values(array_filter($words, fn ($w) => $w !== '' && ! in_array($w, self::GENERIC, true) && ! ctype_digit($w)));
    }

    /** Same development: the distinctive words of one name all sit in the other ("Avida Riala Tower 4" / "Avida Towers Riala"). */
    private static function family(int $a, int $b, array $index): bool
    {
        $da = self::distinctive($a, $index);
        $db = self::distinctive($b, $index);
        $ta = explode(' ', self::normalize($index['projects'][$a]['name']));
        $tb = explode(' ', self::normalize($index['projects'][$b]['name']));

        return $da !== [] && $db !== [] && (array_diff($da, $tb) === [] || array_diff($db, $ta) === []);
    }

    /** The tagged project is itself named in the text, or most of its distinctive words are, or its letters are (J Tower / JTower). */
    private static function agrees(int $id, string $text, array $index): bool
    {
        $name = self::normalize(explode(',', $index['projects'][$id]['name'])[0]);
        $h = ' '.self::normalize($text).' ';
        if (str_contains($h, ' '.$name.' ')) {
            return true;
        }
        $d = self::distinctive($id, $index);
        $tokens = explode(' ', trim($h));
        if ($d !== [] && count(array_intersect($d, $tokens)) * 2 >= count($d)) {
            return true;
        }
        $squash = str_replace(' ', '', $name);

        return mb_strlen($squash) >= 6 && str_contains(str_replace(' ', '', $h), $squash);
    }
}
