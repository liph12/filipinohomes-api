<?php

namespace App\Console\Commands\Concerns;

/**
 * What the two LR status commands share: whole-number option parsing, pacing,
 * and giving way when LR's rate-limit budget runs low. That budget (60 a minute
 * per IP) is shared with real sign-ins — see config/lr_status.php.
 *
 * For a class that extends Illuminate\Console\Command.
 */
trait PacesLrCalls
{
    /**
     * A whole-number option: null when it wasn't given, false when it was but
     * isn't digits ("abc", "1s", "-5") — which would otherwise quietly become 0
     * and switch the pacing off.
     */
    protected function wholeOption(string $name): int|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string) $value) ? (int) $value : false;
    }

    protected function pause(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }

    /** Wait after LR answers 429. */
    protected function backOff(): void
    {
        $this->pause(max(0, (int) config('lr_status.backoff_seconds', 60)) * 1_000_000);
    }

    /** Wait a while when LR says its shared budget is running low. */
    protected function giveWayWhenLow(?int $remaining): void
    {
        if ($remaining === null || $remaining >= (int) config('lr_status.min_remaining', 20)) {
            return;
        }

        $seconds = max(0, (int) config('lr_status.backoff_seconds', 60));
        $this->line("  LR budget low ({$remaining} left) — pausing {$seconds}s");
        $this->pause($seconds * 1_000_000);
    }

    protected function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
