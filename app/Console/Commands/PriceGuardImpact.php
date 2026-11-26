<?php

namespace App\Console\Commands;

use App\Http\Controllers\SitemapController;
use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * What the price guard would do to the programmatic price pages, before it is switched on. Read-only.
 *
 * The sitemap feed behind `GET /sitemap/query-counts` lists the "affordable" and "under 1M / 3M / 5M" pages that have at
 * least 10 listings (a band that covers more than 85% of its cohort is left out as a duplicate of the base page). With
 * the guard on, a price that cannot be an asking price (a lot's price per sqm, a rent typed as 25) no longer counts, so:
 *
 *   leave    the page falls under the floor: the site renders it noindex and drops it from the sitemap
 *   appear   a band stops looking like the whole cohort once the junk is out, so it becomes a page
 *   shrink   the page stays, with fewer listings (the ones within a few listings of the floor are the next to go)
 *
 * The feed is built twice in memory, guard off and guard on, whatever the real switch says; nothing is written.
 * `--csv` writes every changed page with its URL path so it can be checked against Search Console before the switch.
 */
class PriceGuardImpact extends Command
{
    protected $signature = 'listings:price-guard-impact {--csv= : write every changed page to this CSV file} {--limit=25 : pages to list per group}';

    protected $description = 'Which price pages leave, appear or shrink once the price guard is on (read-only)';

    /** The feed only lists pages with at least this many listings (SitemapController::queryCounts). */
    private const FLOOR = 10;

    /** How close to the floor a page counts as "next to go". */
    private const NEAR = 2;

    /** The categories the site has pages for (the frontend's sitemap shard skips the rest). */
    private const SEGMENT = ['For Sale' => 'for-sale', 'For Rent' => 'for-rent'];

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $off = $this->feed(false);
        $on = $this->feed(true);

        $leave = array_diff_key($off, $on);
        $appear = array_diff_key($on, $off);
        $shrink = [];
        foreach ($on as $key => $after) {
            if (isset($off[$key]) && $off[$key] !== $after) {
                $shrink[$key] = [$off[$key], $after];
            }
        }

        $this->line('Guard in the settings now: '.(ListingFeedSwitch::priceGuard() ? 'on' : 'off').'. This report forces it off and on in memory and writes nothing.');
        $this->info(sprintf('Price pages in the sitemap feed: %d now, %d once the guard is on.', count($off), count($on)));
        $this->table(['', 'pages', 'by modifier'], [
            ['leave (noindex, out of the sitemap)', count($leave), $this->byModifier($leave)],
            ['appear', count($appear), $this->byModifier($appear)],
            ['stay with fewer listings', count($shrink), $this->byModifier($shrink)],
            ['  of which next to go (at most '.(self::FLOOR + self::NEAR).' left)', count(array_filter($shrink, fn ($c) => $c[1] <= self::FLOOR + self::NEAR)), ''],
        ]);

        if ($leave !== []) {
            $this->newLine();
            $this->line('Pages that leave (listings now, most first):');
            arsort($leave);
            $this->table(['now', 'path'], array_map(fn ($key, $n) => [$n, $this->path($key)], array_keys(array_slice($leave, 0, $limit, true)), array_slice($leave, 0, $limit, true)));
        }
        if ($appear !== []) {
            $this->newLine();
            $this->line('Pages that appear (listings after):');
            arsort($appear);
            $this->table(['after', 'path'], array_map(fn ($key, $n) => [$n, $this->path($key)], array_keys(array_slice($appear, 0, $limit, true)), array_slice($appear, 0, $limit, true)));
        }
        if ($shrink !== []) {
            $this->newLine();
            $this->line('Pages that stay but lose the most listings:');
            uasort($shrink, fn ($a, $b) => ($b[0] - $b[1]) <=> ($a[0] - $a[1]));
            $top = array_slice($shrink, 0, $limit, true);
            $this->table(['before', 'after', 'path'], array_map(fn ($key, $c) => [$c[0], $c[1], $this->path($key)], array_keys($top), $top));
        }

        if ($file = $this->option('csv')) {
            $written = $this->writeCsv((string) $file, $off, $on, $leave, $appear, $shrink);
            if ($written === null) {
                $this->error("Could not write {$file}.");

                return self::FAILURE;
            }
            $this->info("Wrote {$written} changed pages to {$file}.");
        }

        return self::SUCCESS;
    }

    /**
     * The feed rows as "modifier|category|type|city|province" => listings, the pages the site really has.
     *
     * @return array<string, int>
     */
    private function feed(bool $guard): array
    {
        return ListingFeedSwitch::pretendingGuard($guard, function (): array {
            $rows = app(SitemapController::class)->queryCounts()->getData(true);

            $out = [];
            foreach ($rows as $r) {
                if (! isset(self::SEGMENT[$r['category']])) {
                    continue;
                }
                $out[implode('|', [$r['modifier'], $r['category'], $r['type'], $r['city'], $r['province']])] = (int) $r['total'];
            }

            return $out;
        });
    }

    /** @param array<string, mixed> $set */
    private function byModifier(array $set): string
    {
        $count = [];
        foreach (array_keys($set) as $key) {
            $modifier = explode('|', $key)[0];
            $count[$modifier] = ($count[$modifier] ?? 0) + 1;
        }
        ksort($count);

        return implode(', ', array_map(fn ($m, $n) => "{$m} {$n}", array_keys($count), $count));
    }

    /** The page's URL path, built the way the site builds it: /{category}/{type}/{modifier}/in-{city}-{province}. */
    private function path(string $key): string
    {
        [$modifier, $category, $type, $city, $province] = explode('|', $key);

        return '/'.self::SEGMENT[$category].'/'.$this->slug($type)."/{$modifier}/in-".$this->slug($city).'-'.$this->slug($province);
    }

    /** The site's slug: diacritics stripped, lower case, anything but letters, digits, spaces and hyphens dropped. */
    private function slug(string $text): string
    {
        $text = strtolower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text) ?? '';
        $text = preg_replace('/\s+/', '-', $text) ?? '';
        $text = preg_replace('/-+/', '-', $text) ?? '';

        return trim($text, '-');
    }

    /**
     * @param  array<string, int>  $off
     * @param  array<string, int>  $on
     * @param  array<string, int>  $leave
     * @param  array<string, int>  $appear
     * @param  array<string, array{0: int, 1: int}>  $shrink
     * @return int|null the number of rows written, null if the file could not be written
     */
    private function writeCsv(string $file, array $off, array $on, array $leave, array $appear, array $shrink): ?int
    {
        $handle = @fopen($file, 'w');
        if ($handle === false) {
            return null;
        }

        fputcsv($handle, ['change', 'modifier', 'category', 'type', 'city', 'province', 'before', 'after', 'path']);
        $rows = 0;
        $emit = function (string $change, string $key, int $before, int $after) use ($handle, &$rows): void {
            [$modifier, $category, $type, $city, $province] = explode('|', $key);
            fputcsv($handle, [$change, $modifier, $category, $type, $city, $province, $before, $after, $this->path($key)]);
            $rows++;
        };
        foreach ($leave as $key => $before) {
            $emit('leave', $key, $before, 0);
        }
        foreach ($appear as $key => $after) {
            $emit('appear', $key, 0, $after);
        }
        foreach ($shrink as $key => [$before, $after]) {
            $emit('shrink', $key, $before, $after);
        }
        fclose($handle);

        return $rows;
    }
}
