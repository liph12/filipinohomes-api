<?php

namespace App\Services\Listing;

use App\Support\PhilippinePin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which listings the hourly rotation stamps next, when the `listings.rotation.picker` switch says 'fair'.
 *
 * The rotation (POST /listings/update-batch, App\Services\Jobs\ListingService) sets `updated_at = now()` on a batch
 * of listings each time it is called, and the result pages show the newest `updated_at` first, so the batch leads.
 * Built to give every listing a turn, it walked the ids in order and stamped whatever it met: sold, private and
 * junk-priced listings took turns that no buyer could use. This picker keeps the idea and the endpoint and
 * changes only WHICH listings it stamps:
 *
 *   pool        only listings a buyer can act on: public, available, an active agent, a price that can be shown
 *   a queue     the pool laid out on a circle. A listing's seat is frac(hash(agent) + k × 0.618…), k being its
 *               number among that agent's listings, so one agent's listings sit as far apart as the circle
 *               allows (and so do the copies of one listing). A lap is one trip around the circle; the cursor
 *               remembers where the last batch ended.
 *   classes     complete (5+ photos, a 120+ character description, a map pin inside the Philippines, an area):
 *               a turn every lap. Not complete: every `incomplete_every`-th lap. Held back (a copy of the
 *               agent's own listing, a strong "sold" claim in a chat in the last 30 days, an agent who answers
 *               under half of 5+ inquiries, an agent who has not signed in for `agent_idle_days`): every
 *               `heldback_every`-th lap. Nothing is hidden: held-back listings keep their place in the results,
 *               they just are not re-stamped as often.
 *   stagger     the batch is stamped a second apart, in queue order, so it appears in that order
 *
 * "Never signed in" is NOT idle: sign-in tracking is recent and most agents work through the company's own
 * systems, so that would hold back almost every listing. Only a recorded sign-in older than the dial counts.
 *
 * State lives in the existing `scheduler_states` table (a queue position and a lap count). Nothing is
 * written except `listings.updated_at` (by the query builder: no model events, no audit rows, no queued jobs).
 */
final class ListingRotationPicker
{
    public const CURSOR_KEY = 'listing_rotation_fair';

    public const LAP_KEY = 'listing_rotation_fair_lap';

    /** Queue positions are integers 0..SCALE-1. */
    private const SCALE = 1_000_000_000;

    private const GOLDEN = 0.6180339887498949;

    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    public const HELD_BACK = 'heldback';

    /**
     * The whole queue, in queue order.
     *
     * @return list<array{id: int, agent_id: int, pos: int, class: string, reasons: list<string>, price: float, title: string}>
     */
    public static function queue(): array
    {
        $rows = self::poolRows();
        if ($rows === []) {
            return [];
        }

        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $claimed = self::strongClaims($ids);
        $copies = self::copies($rows);
        $idleBefore = now()->subDays(ListingFeedSwitch::dial('agent_idle_days'));

        // Each agent's listings, by id, to number them.
        $byAgent = [];
        foreach ($rows as $r) {
            $byAgent[(int) $r->agent_id][] = (int) $r->id;
        }
        $rank = [];
        foreach ($byAgent as $list) {
            sort($list);
            foreach ($list as $k => $id) {
                $rank[$id] = $k;
            }
        }

        $queue = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $reasons = [];
            if (isset($copies[$id])) {
                $reasons[] = 'copy';
            }
            if (isset($claimed[$id])) {
                $reasons[] = 'sold_claim';
            }
            if ((int) $r->response_sample_size >= 5 && (float) $r->unanswered_response_pct >= 50) {
                $reasons[] = 'agent_unanswered';
            }
            if ($r->last_online_at !== null && $r->last_online_at < $idleBefore->toDateTimeString()) {
                $reasons[] = 'agent_idle';
            }

            $class = $reasons !== [] ? self::HELD_BACK : (self::isComplete($r) ? self::COMPLETE : self::INCOMPLETE);
            $queue[] = [
                'id' => $id,
                'agent_id' => (int) $r->agent_id,
                'pos' => self::seat((int) $r->agent_id, $rank[$id] ?? 0),
                'class' => $class,
                'reasons' => $reasons,
                'price' => (float) $r->price,
                'title' => (string) $r->name,
            ];
        }

        usort($queue, fn ($a, $b) => [$a['pos'], $a['id']] <=> [$b['pos'], $b['id']]);

        return $queue;
    }

    /**
     * Walk the queue from a cursor and take the listings whose turn it is.
     *
     * @param  list<array{id: int, pos: int, class: string}>  $queue
     * @return array{picked: list<array<string, mixed>>, cursor: int, lap: int}
     */
    public static function pick(array $queue, int $cursor, int $lap, int $limit): array
    {
        $picked = [];
        $count = count($queue);
        if ($count === 0 || $limit < 1) {
            return ['picked' => [], 'cursor' => $cursor, 'lap' => $lap];
        }

        $incompleteEvery = ListingFeedSwitch::dial('incomplete_every');
        $heldEvery = ListingFeedSwitch::dial('heldback_every');

        // First entry at or after the cursor.
        $i = 0;
        while ($i < $count && $queue[$i]['pos'] < $cursor) {
            $i++;
        }

        // At most one trip round the circle per call, so no listing is looked at (or stamped) twice in one batch
        // when the pool is smaller than the batch; the next call carries on from where this one stopped.
        for ($steps = 0; count($picked) < $limit && $steps < $count; $steps++) {
            if ($i >= $count) {
                $i = 0;
                $lap++;
            }
            $entry = $queue[$i];
            $every = match ($entry['class']) {
                self::COMPLETE => 1,
                self::INCOMPLETE => $incompleteEvery,
                default => $heldEvery,
            };
            if ((($lap + $entry['id']) % $every) === 0) {
                $picked[] = $entry;
            }
            $cursor = $entry['pos'] + 1;
            $i++;
        }

        return ['picked' => $picked, 'cursor' => $cursor, 'lap' => $lap];
    }

    /**
     * What the next call would stamp, and the pool it draws from. Writes nothing.
     *
     * @return array<string, mixed>
     */
    public static function preview(?int $limit = null): array
    {
        $queue = self::queue();
        [$cursor, $lap] = self::state();
        $result = self::pick($queue, $cursor, $lap, $limit ?? ListingFeedSwitch::dial('batch'));

        return [
            'pool' => count($queue),
            'classes' => self::tally($queue, 'class'),
            'reasons' => self::reasonCounts($queue),
            'turns_per_lap' => self::turnsPerLap($queue),
            'cursor' => $cursor,
            'lap' => $lap,
            'next' => $result['picked'],
        ];
    }

    /**
     * Stamp the next batch (a second apart, in queue order) and move the cursor.
     *
     * @return array{message: string, processed: int, picker: string, lap: int, pool: int}
     */
    public static function run(): array
    {
        $queue = self::queue();
        [$cursor, $lap] = self::state();
        $result = self::pick($queue, $cursor, $lap, ListingFeedSwitch::dial('batch'));

        DB::transaction(function () use ($result) {
            $now = now();
            foreach ($result['picked'] as $i => $entry) {
                // The query builder: no model events, no audit rows, no queued index pings.
                DB::table('listings')->where('id', $entry['id'])->update([
                    'updated_at' => $now->copy()->subSeconds($i)->toDateTimeString(),
                ]);
            }
            self::saveState($result['cursor'], $result['lap']);
        });

        return [
            'message' => $result['picked'] === [] ? 'Nothing due' : 'Batch updated',
            'processed' => count($result['picked']),
            'picker' => 'fair',
            'lap' => $result['lap'],
            'pool' => count($queue),
        ];
    }

    /** @return array{0: int, 1: int} the next queue position to consider, and the lap */
    public static function state(): array
    {
        $cursor = DB::table('scheduler_states')->where('key', self::CURSOR_KEY)->value('last_id');
        $lap = DB::table('scheduler_states')->where('key', self::LAP_KEY)->value('last_id');

        return [(int) ($cursor ?? 0), (int) ($lap ?? 0)];
    }

    /** A listing's seat on the circle. Public so a test can prove one agent's listings are spread apart. */
    public static function seat(int $agentId, int $k): int
    {
        $base = (crc32((string) $agentId) % self::SCALE) / self::SCALE;
        $x = $base + $k * self::GOLDEN;

        return (int) floor(($x - floor($x)) * self::SCALE);
    }

    private static function saveState(int $cursor, int $lap): void
    {
        foreach ([self::CURSOR_KEY => $cursor, self::LAP_KEY => $lap] as $key => $value) {
            DB::table('scheduler_states')->updateOrInsert(
                ['key' => $key],
                ['last_id' => max(0, $value), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /**
     * Listings that can take a turn, with what is needed to class them: one query.
     *
     * @return list<object>
     */
    private static function poolRows(): array
    {
        [$ok, $bindings] = ListingPriceRules::believableSql(ListingFeedSwitch::approvedPrices(), 'l');
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $photos = $sqlite ? 'COALESCE(json_array_length(p.photos), 0)' : 'COALESCE(JSON_LENGTH(p.photos), 0)';
        $chars = $sqlite ? "LENGTH(COALESCE(p.description, ''))" : "CHAR_LENGTH(COALESCE(p.description, ''))";

        return DB::select(
            "SELECT l.id, l.agent_id, l.category_id, l.price, l.name,
                    {$photos} AS photo_count, {$chars} AS description_chars, p.geo_coordinates,
                    pa.lot_area, pa.floor_area, pt.name AS type_name,
                    u.last_online_at, ag.unanswered_response_pct, ag.response_sample_size
             FROM listings l
             JOIN properties p ON p.id = l.property_id AND p.deleted_at IS NULL AND p.status = 'active'
             JOIN agents ag ON ag.id = l.agent_id AND ag.status = 'active' AND ag.deleted_at IS NULL
             LEFT JOIN users u ON u.id = ag.user_id
             LEFT JOIN property_attributes pa ON pa.id = p.property_attribute_id
             LEFT JOIN property_subtypes ps ON ps.id = pa.property_subtype_id
             LEFT JOIN property_types pt ON pt.id = ps.property_type_id
             WHERE l.deleted_at IS NULL AND l.visibility = 'public' AND {$ok}
             ORDER BY l.id",
            $bindings,
        );
    }

    /** The same four things Listing Insights → Quality counts (ListingQualityService), applied to one row. */
    private static function isComplete(object $r): bool
    {
        if ((int) $r->photo_count < 5 || (int) $r->description_chars < 120) {
            return false;
        }

        $geo = is_string($r->geo_coordinates) ? json_decode($r->geo_coordinates, true) : null;
        $lat = is_array($geo) ? PhilippinePin::number($geo['lat'] ?? null) : null;
        $lng = is_array($geo) ? PhilippinePin::number($geo['lng'] ?? null) : null;
        if ($lat === null || $lng === null || ! PhilippinePin::inside($lat, $lng)) {
            return false;
        }

        $lot = (float) ($r->lot_area ?? 0);
        $floor = (float) ($r->floor_area ?? 0);
        $landLike = in_array((string) $r->type_name, ['Land', 'Commercial'], true);

        return $landLike ? max($lot, $floor) >= 10 : $floor >= 10;
    }

    /**
     * The newer copies of a listing: the same agent, category and title with a price within 2%. The oldest of
     * each set is the original and is exempt.
     *
     * @param  list<object>  $rows  ordered by id
     * @return array<int, true>
     */
    private static function copies(array $rows): array
    {
        $kept = [];
        $copies = [];
        foreach ($rows as $r) {
            $title = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $r->name)));
            if ($title === '') {
                continue;
            }
            $key = $r->agent_id.'|'.$r->category_id.'|'.$title;
            $price = (float) $r->price;
            $isCopy = false;
            foreach ($kept[$key] ?? [] as $keptPrice) {
                if ($keptPrice > 0 && abs($price - $keptPrice) <= 0.02 * $keptPrice) {
                    $isCopy = true;
                    break;
                }
            }
            if ($isCopy) {
                $copies[(int) $r->id] = true;
            } else {
                $kept[$key][] = $price;
            }
        }

        return $copies;
    }

    /**
     * Listings a chat recently called sold or taken, in no uncertain words (the matcher's strong tier).
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private static function strongClaims(array $ids): array
    {
        if (! Schema::hasTable('listing_sold_claims')) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('listing_sold_claims')->whereIn('listing_id', $chunk)->where('tier', 'strong')
                ->where('said_at', '>=', now()->subDays(30))->pluck('listing_id') as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $queue
     * @return array<string, int>
     */
    private static function tally(array $queue, string $field): array
    {
        $out = [];
        foreach ($queue as $e) {
            $out[$e[$field]] = ($out[$e[$field]] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $queue
     * @return array<string, int>
     */
    private static function reasonCounts(array $queue): array
    {
        $out = [];
        foreach ($queue as $e) {
            foreach ($e['reasons'] as $reason) {
                $out[$reason] = ($out[$reason] ?? 0) + 1;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * How many stamps one lap of the queue hands out, on average over the repeating laps.
     *
     * @param  list<array<string, mixed>>  $queue
     */
    private static function turnsPerLap(array $queue): float
    {
        $classes = self::tally($queue, 'class');

        return ($classes[self::COMPLETE] ?? 0)
            + ($classes[self::INCOMPLETE] ?? 0) / ListingFeedSwitch::dial('incomplete_every')
            + ($classes[self::HELD_BACK] ?? 0) / ListingFeedSwitch::dial('heldback_every');
    }
}
