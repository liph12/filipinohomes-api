<?php

namespace Tests\Feature;

use App\Support\SingleFlight;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Something heavy that two requests ask for at once is worked out once.
 */
class SingleFlightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_is_worked_out_once_and_kept(): void
    {
        $built = 0;
        $build = function () use (&$built): string {
            $built++;

            return 'the number';
        };

        $this->assertSame('the number', SingleFlight::remember('sf:once', 60, $build));
        $this->assertSame('the number', SingleFlight::remember('sf:once', 60, $build));
        $this->assertSame(1, $built);
        $this->assertSame('the number', Cache::get('sf:once'));
    }

    public function test_a_request_that_finds_it_being_worked_out_waits_for_it_and_builds_nothing(): void
    {
        $theirs = Cache::lock('sf:busy:build', 60);
        $this->assertTrue($theirs->get());

        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($theirs): void {
            // The other request finishes: it kept what it worked out and let go.
            Cache::put('sf:busy', 'worked out elsewhere', 60);
            $theirs->release();
        });

        $built = 0;

        $value = SingleFlight::remember('sf:busy', 60, function () use (&$built): string {
            $built++;

            return 'mine';
        });

        $this->assertSame('worked out elsewhere', $value);
        $this->assertSame(0, $built);
    }

    public function test_a_build_that_takes_too_long_is_not_waited_on(): void
    {
        $theirs = Cache::lock('sf:slow:build', 600);
        $this->assertTrue($theirs->get());
        Sleep::fake();

        $value = SingleFlight::remember('sf:slow', 60, fn (): string => 'mine', wait: 1);

        // It waited, then worked it out itself; the other's hold is still the other's.
        $this->assertSame('mine', $value);
        $this->assertFalse(Cache::lock('sf:slow:build', 60)->get());
    }

    public function test_the_hold_is_let_go_when_it_is_done_and_when_it_fails(): void
    {
        SingleFlight::remember('sf:done', 60, fn (): string => 'x');
        $this->assertTrue(Cache::lock('sf:done:build', 5)->get());

        try {
            SingleFlight::remember('sf:fails', 60, fn () => throw new \RuntimeException('no'));
            $this->fail('the failure was swallowed');
        } catch (\RuntimeException) {
            // The next one is not kept waiting for a hold that was never let go.
            $this->assertTrue(Cache::lock('sf:fails:build', 5)->get());
        }
    }
}
