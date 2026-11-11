<?php

namespace Tests\Feature;

use App\Services\Reports\Executive\Analysis\ExecutiveAnalysisService;
use App\Services\Reports\Executive\ReportRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/**
 * The AI Analysis card: written on demand, stored per date range, never
 * re-billed for unchanged numbers, and never showing a number the report
 * didn't compute. OpenAI is faked throughout.
 */
class ExecutiveAnalysisTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private ReportRange $range;

    private Carbon $now;

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->now = Carbon::parse('2026-11-10 14:00', 'Asia/Manila');
        Carbon::setTestNow($this->now);
        $this->createExecutiveReportTables();
        $this->id = $this->seedExecutiveScenario();
        $this->range = ReportRange::resolve('2026-11-01', '2026-11-10');
        config(['services.openai.key' => null, 'services.openai.chat_model' => 'gpt-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private static function reply(array $arguments): CreateResponse
    {
        return CreateResponse::fake(['choices' => [[
            'message' => ['content' => null, 'function_call' => ['name' => 'write_executive_analysis', 'arguments' => json_encode($arguments)]],
        ]]]);
    }

    private static function goodAnswer(): array
    {
        return [
            'headline' => '{deals_total} deals closed as {inquiries_received} inquiries came in.',
            'bullets' => [
                ['text' => '{agents_dormant_30d} agent has not opened FH in a month.', 'fact_ids' => ['agents_dormant_30d'], 'kind' => 'risk'],
                ['text' => 'Listings added rose {listings_added.change} to {listings_added}, from {listings_added.prev}.', 'fact_ids' => ['listings_added'], 'kind' => 'win'],
                ['text' => 'Ask {closers_1.name} to coach the team on closing.', 'fact_ids' => ['closers_1'], 'kind' => 'action'],
            ],
        ];
    }

    private function service(?ClientFake $fake = null): ExecutiveAnalysisService
    {
        $service = app(ExecutiveAnalysisService::class);

        return $fake ? $service->withClient($fake) : $service;
    }

    public function test_without_a_key_the_card_is_unavailable(): void
    {
        $service = $this->service();

        $this->assertSame(['status' => 'unavailable'], $service->generate($this->range, $this->now, null));
        $this->assertSame(['status' => 'none', 'available' => false], $service->stored($this->range, $this->now));
    }

    public function test_it_writes_stores_and_reuses_the_analysis(): void
    {
        $fake = new ClientFake([self::reply(self::goodAnswer())]);

        $first = $this->service($fake)->generate($this->range, $this->now, $this->id['u1']);
        $this->assertSame('ok', $first['status']);
        $analysis = $first['analysis'];
        $this->assertSame(['risk', 'win', 'action'], array_column($analysis['bullets'], 'kind'));
        $this->assertSame(['2', 'deals.total'], [$analysis['headline'][0]['text'], $analysis['headline'][0]['metric']]);
        $this->assertSame('Agent '.$this->id['u1'], $analysis['bullets'][2]['segments'][1]['text'], 'The name comes from the facts.');
        $prev = collect($analysis['bullets'][1]['segments'])->firstWhere('fact', 'listings_added');
        $this->assertSame('9', $prev['text']);
        $this->assertSame(['start' => '2026-10-01', 'end' => '2026-10-10'], collect($analysis['bullets'][1]['segments'])->where('fact', 'listings_added')->last()['range']);

        $fake->assertSent(Chat::class, fn (string $method, array $p) => $method === 'create'
            && $p['model'] === 'gpt-test'
            && $p['function_call'] === ['name' => 'write_executive_analysis']
            && ! array_key_exists('temperature', $p)
            && ! array_key_exists('max_tokens', $p)
            && str_contains($p['messages'][1]['content'], '{agents_dormant_30d} | Dormant 30+ days | value 1'));

        $row = DB::table('executive_report_analyses')->first();
        $this->assertSame(['2026-11-01', '2026-11-10', $this->id['u1']], [$row->date_start, $row->date_end, (int) $row->user_id]);

        // Reopening and re-asking with unchanged numbers never calls the model again.
        $this->assertSame('ok', $this->service($fake)->stored($this->range, $this->now)['status']);
        $again = $this->service($fake)->generate($this->range, $this->now, null);
        $this->assertTrue($again['reused']);
        $fake->assertSent(Chat::class, 1);
        $this->assertSame(1, DB::table('executive_report_analyses')->count());
    }

    public function test_reading_never_calls_the_model(): void
    {
        $fake = new ClientFake([]);

        $this->assertSame('none', $this->service($fake)->stored($this->range, $this->now)['status']);
        $fake->assertNothingSent();
    }

    public function test_an_answer_with_its_own_numbers_is_refused_and_not_saved(): void
    {
        $answer = self::goodAnswer();
        $answer['bullets'][0]['text'] = '42 agents are dormant.';
        $answer['bullets'][1]['text'] = 'Listings rose 800%.';

        $result = $this->service(new ClientFake([self::reply($answer)]))->generate($this->range, $this->now, null);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, DB::table('executive_report_analyses')->count());
    }

    public function test_a_failed_call_is_an_error_not_an_exception(): void
    {
        $result = $this->service(new ClientFake([new \RuntimeException('cURL error 28: timed out')]))->generate($this->range, $this->now, null);

        $this->assertSame('error', $result['status']);
    }

    public function test_regenerate_waits_a_minute_then_writes_a_new_one(): void
    {
        $fake = new ClientFake([self::reply(self::goodAnswer()), self::reply(self::goodAnswer())]);
        $service = $this->service($fake);
        $service->generate($this->range, $this->now, null);

        $soon = $service->generate($this->range, $this->now->copy()->addSeconds(30), null, regenerate: true);
        $this->assertSame(30, $soon['cooldown']);
        $fake->assertSent(Chat::class, 1);

        $later = $service->generate($this->range, $this->now->copy()->addSeconds(61), null, regenerate: true);
        $this->assertSame('ok', $later['status']);
        $fake->assertSent(Chat::class, 2);
        $this->assertSame(2, DB::table('executive_report_analyses')->count());
    }

    public function test_a_saved_analysis_goes_stale_when_the_numbers_move(): void
    {
        $service = $this->service(new ClientFake([self::reply(self::goodAnswer())]));
        $service->generate($this->range, $this->now, null);
        $this->assertFalse($service->stored($this->range, $this->now)['stale']);

        $this->makeListing($this->id['a1'], ['city' => 10, 'added' => '2026-11-10 09:00']);
        Cache::flush();

        $this->assertTrue($service->stored($this->range, $this->now)['stale']);
    }

    public function test_a_partial_fact_comes_without_a_comparison(): void
    {
        // March 1 is before FH kept inquiries (Mar 22) and sign-ins (Apr 16).
        $facts = $this->service()->factsFor(ReportRange::resolve('2026-03-01', '2026-11-10'), $this->now)['facts'];

        $this->assertTrue($facts['inquiries_received']['partial']);
        $this->assertNull($facts['inquiries_received']['previous']);
        $this->assertNull($facts['inquiries_received']['change_pct']);
        $this->assertFalse($facts['listings_added']['partial']);
        $this->assertNotNull($facts['listings_added']['previous']);
    }

    public function test_the_daily_cap_and_one_call_at_a_time(): void
    {
        $fake = new ClientFake([]);

        Cache::put('exec-report:v1:analysis-calls:2026-11-10', 40, 3600);
        $this->assertSame(['status' => 'limited'], $this->service($fake)->generate($this->range, $this->now, null));

        Cache::flush();
        $lock = Cache::lock('exec-report:v1:analysis:'.$this->range->key(), 90);
        $lock->get();
        $this->assertSame(['status' => 'busy'], $this->service($fake)->generate($this->range, $this->now, null));
        $lock->release();

        $fake->assertNothingSent();
    }
}
