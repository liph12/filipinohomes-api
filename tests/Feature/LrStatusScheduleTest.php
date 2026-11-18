<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The LR status sync's schedule: off until switched on, the nightly apply is a
 * report only, and neither can ever demote anyone on its own.
 */
class LrStatusScheduleTest extends TestCase
{
    private function event(string $command): Event
    {
        // Loading the schedule is what registers routes/console.php's entries.
        Artisan::call('schedule:list');

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, $command));

        $this->assertNotNull($event, "{$command} is not scheduled");

        return $event;
    }

    public function test_the_flag_defaults_to_off_whatever_this_machines_env_says(): void
    {
        // A developer's .env may well switch it on; the shipped default is what
        // matters, so read the config file with that variable removed.
        $key = 'LR_STATUS_SYNC_ENABLED';
        $saved = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        try {
            $this->assertFalse((require config_path('lr_status.php'))['enabled']);
        } finally {
            $saved[0] === false ? putenv($key) : putenv("{$key}={$saved[0]}");
            $saved[1] === null ? null : $_ENV[$key] = $saved[1];
            $saved[2] === null ? null : $_SERVER[$key] = $saved[2];
        }
    }

    public function test_both_runs_are_off_until_the_flag_is_switched_on(): void
    {
        foreach (['agents:sync-lr-status', 'agents:apply-lr-status'] as $command) {
            $event = $this->event($command);

            config(['lr_status.enabled' => false]);
            $this->assertFalse($event->filtersPass($this->app), "{$command} ran while disabled");

            config(['lr_status.enabled' => true]);
            $this->assertTrue($event->filtersPass($this->app), "{$command} did not run once enabled");
        }
    }

    public function test_the_scheduled_apply_is_a_report_and_never_forced(): void
    {
        $command = (string) $this->event('agents:apply-lr-status')->command;

        $this->assertStringNotContainsString('--force', $command);
        $this->assertStringNotContainsString('--status', $command);
    }

    public function test_the_sync_runs_hourly_off_the_slot_the_other_lr_jobs_use(): void
    {
        // natcon:hydrate-awardees and birthdays:backfill-birthdates run at :00
        // and share LR's 60 requests a minute.
        $this->assertSame('40 * * * *', $this->event('agents:sync-lr-status')->expression);
    }

    public function test_the_nightly_report_runs_after_the_dormancy_sweep_in_manila_time(): void
    {
        $event = $this->event('agents:apply-lr-status');

        $this->assertSame('45 2 * * *', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
    }

    public function test_the_sync_runs_in_the_background_so_it_cannot_hold_up_other_scheduled_work(): void
    {
        // A run can last up to an hour. A foreground event holds up everything
        // scheduled after it in the same schedule:run — and, on a host whose
        // scheduler is one long loop rather than a process a minute, every run
        // after it too (the NATCON outbox drain among them).
        $this->assertTrue($this->event('agents:sync-lr-status')->runInBackground);
    }

    public function test_both_hold_an_overlap_lock_that_expires(): void
    {
        // The default mutex lasts 24 h and a crashed run would keep it that
        // long (the scheduler uses file mutexes).
        foreach (['agents:sync-lr-status' => 90, 'agents:apply-lr-status' => 30] as $command => $minutes) {
            $event = $this->event($command);

            $this->assertTrue($event->withoutOverlapping, "{$command} can overlap itself");
            $this->assertSame($minutes, $event->expiresAt, $command);
        }
    }
}
