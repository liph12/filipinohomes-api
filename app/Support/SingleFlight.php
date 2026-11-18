<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Cache::remember() for something heavy that two requests may ask for at once:
 * the first works it out, the second waits for it and reads what it kept,
 * instead of working out the same thing again at the same time.
 *
 * A build that takes longer than `$wait` seconds is not waited on: the waiter
 * works it out itself, so a build that hangs never hangs everyone behind it.
 */
final class SingleFlight
{
    /**
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    public static function remember(string $key, int $seconds, Closure $build, int $wait = 25): mixed
    {
        $kept = Cache::get($key);

        if ($kept !== null) {
            return $kept;
        }

        try {
            return Cache::lock($key.':build', 60)->block($wait, function () use ($key, $seconds, $build): mixed {
                $kept = Cache::get($key);

                if ($kept !== null) {
                    return $kept;
                }

                $value = $build();
                Cache::put($key, $value, $seconds);

                return $value;
            });
        } catch (LockTimeoutException) {
            return Cache::remember($key, $seconds, $build);
        }
    }
}
