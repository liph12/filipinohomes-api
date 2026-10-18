<?php

namespace App\Natcon\Console;

use App\Natcon\Models\NatconEvent;
use App\Natcon\Services\LiveStreamEmbed;
use App\Natcon\Services\YouTubeLiveChatCollector;
use Illuminate\Console\Command;

/**
 * Collects the live stream's YouTube chat while the admin's Live switch is on.
 *
 * Scheduled every minute (withoutOverlapping). Each run reads pages for up to ~50 seconds, waiting
 * between pages as long as YouTube asks, so the minute is covered without two runs overlapping.
 * No-ops when nothing is live, when the link is not a YouTube video, or when no credentials are set.
 */
class CollectLiveChat extends Command
{
    protected $signature = 'natcon:collect-live-chat {--budget=50 : seconds to spend reading pages this run}';

    protected $description = 'Store the live YouTube chat of the active convention stream (for the live-page raffle).';

    public function handle(YouTubeLiveChatCollector $collector): int
    {
        $event = NatconEvent::active();
        if (! $event || ! $event->live_enabled) {
            $this->line('Nothing live.');

            return self::SUCCESS;
        }
        $embed = LiveStreamEmbed::for($event->live_url);
        $videoId = $embed['video_id'] ?? null;
        if (($embed['provider'] ?? null) !== 'youtube' || ! $videoId) {
            $this->line('Live link is not a YouTube video — no chat to collect.');

            return self::SUCCESS;
        }

        $deadline = microtime(true) + max(5, (int) $this->option('budget'));
        $total = 0;
        do {
            $r = $collector->collect($event, $videoId);
            $total += $r['inserted'];
            if ($r['status'] !== 'stored') {
                $this->line("Stopped: {$r['status']} (stored {$total}).");

                return self::SUCCESS;
            }
            $sleep = min($r['wait_ms'], max(0, (int) (($deadline - microtime(true)) * 1000)));
            if ($sleep > 0 && microtime(true) + $sleep / 1000 < $deadline) {
                usleep($sleep * 1000);
            }
        } while (microtime(true) < $deadline);

        $this->line("Stored {$total} new messages.");

        return self::SUCCESS;
    }
}
