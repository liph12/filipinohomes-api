<?php

namespace Tests\Unit;

use App\Services\Listing\AgentAwardCertificatesService;
use App\Services\Listing\ListingTopCreatorsService;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/** Which Top 10 certificates an agent's website shows: finished months only, from April 2026, newest first. */
class AgentAwardCertificatesTest extends TestCase
{
    public function test_lists_finished_months_the_agent_was_top_ten_newest_first(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 10:00', 'Asia/Manila'));

        // Top 10 per month (agent ids in rank order). August is still running, so it's never asked for.
        $months = [
            '2026-07-01' => [5, 7, 9],
            '2026-06-01' => [1, 2],
            '2026-05-01' => [3, 4, 5, 6, 7],
            '2026-04-01' => [7],
        ];
        $creators = Mockery::mock(ListingTopCreatorsService::class);
        $creators->shouldReceive('topCreators')
            ->times(4)
            ->andReturnUsing(function (string $groupBy, string $start, string $end, int $limit) use ($months) {
                $this->assertSame(['agent', 10], [$groupBy, $limit]);
                $this->assertSame(Carbon::parse($start)->endOfMonth()->toDateString(), $end);

                return ['data' => array_map(fn ($id) => ['agent_id' => $id], $months[$start])];
            });

        $awards = (new AgentAwardCertificatesService($creators))->forAgent(7);

        $this->assertSame([
            ['year' => 2026, 'month' => 7, 'rank' => 2],
            ['year' => 2026, 'month' => 5, 'rank' => 5],
            ['year' => 2026, 'month' => 4, 'rank' => 1],
        ], $awards);

        // Cached per month: asking again doesn't re-rank (times(4) above).
        $this->assertSame($awards, (new AgentAwardCertificatesService($creators))->forAgent(7));
        $this->assertSame([], (new AgentAwardCertificatesService($creators))->forAgent(99));
    }
}
