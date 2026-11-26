<?php

namespace App\Console\Commands;

use App\Services\Listing\ListingFeedSwitch;
use App\Services\Listing\ListingRotationPicker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: what the hourly rotation would stamp next under the built-in picker ('id') and under the
 * 'fair' one, side by side, with the pool the fair picker draws from. Writes nothing and moves no cursor.
 *
 *   php artisan listings:rotation-preview [--limit=20]
 */
class RotationPreview extends Command
{
    protected $signature = 'listings:rotation-preview {--limit=20 : how many of each next batch to list}';

    protected $description = 'Show the next rotation batch under the built-in picker and under the fair one (writes nothing)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $batch = ListingFeedSwitch::dial('batch');

        $fair = ListingRotationPicker::preview($batch);

        $this->info('Picker in use: '.ListingFeedSwitch::rotationPicker().'   (switch: listings.rotation.picker = id | fair)');
        $this->newLine();

        $this->line('The fair picker\'s pool: listings a buyer can act on (public, available, active agent, a price that can be shown)');
        $this->table(['', 'listings'], array_merge(
            [['pool', $fair['pool']]],
            array_map(fn ($class, $n) => ["  {$class}", $n], array_keys($fair['classes']), $fair['classes']),
        ));

        $this->line('Held back, by reason (a listing can have more than one):');
        $this->table(['reason', 'listings'], array_map(fn ($r, $n) => [$r, $n], array_keys($fair['reasons']), $fair['reasons']) ?: [['(none)', 0]]);

        $turns = (float) $fair['turns_per_lap'];
        $hours = $turns / $batch;
        $this->line(sprintf(
            'One lap hands out about %s turns: at %d an hour that is %.1f hours (%.1f days). A complete listing gets a turn every lap.',
            number_format($turns), $batch, $hours, $hours / 24,
        ));
        $this->line(sprintf('Queue state: position %s, lap %d.', number_format($fair['cursor']), $fair['lap']));
        $this->newLine();

        $this->line("Next batch under the BUILT-IN picker (the next {$limit} ids after its cursor, whatever they are):");
        $this->table(['id', 'agent', 'title', 'state'], $this->nextByIds($limit));

        $this->line("Next batch under the FAIR picker (first {$limit} of {$batch}):");
        $this->table(
            ['id', 'agent', 'title', 'class'],
            array_map(
                fn ($e) => [$e['id'], $e['agent_id'], mb_strimwidth($e['title'], 0, 50, '…'), $e['class'].($e['reasons'] ? ' ('.implode(', ', $e['reasons']).')' : '')],
                array_slice($fair['next'], 0, $limit),
            ),
        );

        return self::SUCCESS;
    }

    /** @return list<array<int, mixed>> */
    private function nextByIds(int $limit): array
    {
        $last = (int) (DB::table('scheduler_states')->where('key', 'listing_update')->value('last_id') ?? 0);
        $rows = DB::table('listings as l')
            ->leftJoin('properties as p', 'p.id', '=', 'l.property_id')
            ->where('l.id', '>', $last)->orderBy('l.id')->limit($limit)
            ->get(['l.id', 'l.agent_id', 'l.name', 'l.visibility', 'l.deleted_at', 'p.status']);

        return $rows->map(fn ($r) => [
            $r->id, $r->agent_id, mb_strimwidth((string) $r->name, 0, 50, '…'),
            $r->deleted_at ? 'deleted' : ($r->visibility !== 'public' ? 'private' : ($r->status !== 'active' ? (string) $r->status : '')),
        ])->all();
    }
}
