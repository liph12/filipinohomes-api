<?php

namespace App\Jobs;

use App\Services\IndexNowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued IndexNow ping. Model observers dispatch this after the
 * transaction commits so the ping never blocks the user's save
 * request. Retries are intentionally limited — IndexNow is a
 * search-engine hint, not a critical write.
 */
class PingIndexNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 15;
    public int $backoff = 60;

    /**
     * @param string[] $urls Absolute URLs to submit to IndexNow.
     */
    public function __construct(public array $urls)
    {
        $this->onQueue((string) config('services.indexnow.queue', 'default'));
    }

    public function handle(IndexNowService $indexNow): void
    {
        $indexNow->submit($this->urls);
    }
}
