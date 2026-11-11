<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesExecutiveReportTables;
use Tests\TestCase;

/** The Executive Report's endpoints: admins only, validated, the AI route throttled. */
class ExecutiveReportRoutesTest extends TestCase
{
    use CreatesExecutiveReportTables;

    private const DATES = 'date_start=2026-11-01&date_end=2026-11-10';

    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Carbon::setTestNow(Carbon::parse('2026-11-10 14:00', 'Asia/Manila'));
        $this->createExecutiveReportTables();
        $this->id = $this->seedExecutiveScenario();
        config(['services.openai.key' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(int $role): void
    {
        Sanctum::actingAs(User::find($this->makeUser($role)));
    }

    public function test_admins_get_every_endpoint(): void
    {
        $this->actAs(self::ADMIN);

        $this->getJson('/api/admin/executive-report?'.self::DATES)
            ->assertOk()
            ->assertJsonPath('range.start', '2026-11-01')
            ->assertJsonPath('range.previous.start', '2026-10-01')
            ->assertJsonCount(11, 'kpis');
        $this->getJson('/api/admin/executive-report/drill?metric=listings.added&city_id=10&'.self::DATES)
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('filter_labels.city_id', 'Cebu City');
        $this->getJson('/api/admin/executive-report/explorer?type=1&province_id=1&'.self::DATES)
            ->assertOk()
            ->assertJsonPath('level', 'city');
        $this->getJson('/api/admin/executive-report/sold-claims?'.self::DATES)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/executive-report/analysis?'.self::DATES)
            ->assertOk()
            ->assertJsonPath('status', 'none')
            ->assertJsonPath('available', false);
    }

    public function test_without_dates_it_is_the_last_thirty_days(): void
    {
        $this->actAs(self::ADMIN);

        $this->getJson('/api/admin/executive-report')
            ->assertOk()
            ->assertJsonPath('range.start', '2026-10-12')
            ->assertJsonPath('range.end', '2026-11-10');
    }

    public function test_agents_and_editors_are_refused(): void
    {
        foreach ([self::AGENT, self::EDITOR, self::SECRETARY] as $role) {
            $this->actAs($role);
            $this->getJson('/api/admin/executive-report?'.self::DATES)->assertForbidden();
            $this->getJson('/api/admin/executive-report/drill?metric=listings.added&'.self::DATES)->assertForbidden();
            $this->postJson('/api/admin/executive-report/analysis', ['date_start' => '2026-11-01', 'date_end' => '2026-11-10'])->assertForbidden();
        }
    }

    public function test_bad_requests_are_422(): void
    {
        $this->actAs(self::ADMIN);

        $this->getJson('/api/admin/executive-report?date_start=2026-11-05&date_end=2026-11-01')->assertUnprocessable();
        $this->getJson('/api/admin/executive-report?date_start=2025-01-01&date_end=2026-11-10')->assertUnprocessable(); // over a year
        $this->getJson('/api/admin/executive-report/drill?metric=nope&'.self::DATES)->assertUnprocessable();
        $this->getJson('/api/admin/executive-report/drill?metric=agents.active&city_id=10&'.self::DATES)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filters');
        $this->getJson('/api/admin/executive-report/drill?metric=listings.added&psa_region=atlantis&'.self::DATES)->assertUnprocessable();
        $this->getJson('/api/admin/executive-report/explorer?project_id=3&'.self::DATES)->assertUnprocessable();
    }

    public function test_the_ai_route_is_throttled(): void
    {
        $this->actAs(self::ADMIN);
        $body = ['date_start' => '2026-11-01', 'date_end' => '2026-11-10'];

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/admin/executive-report/analysis', $body)->assertOk()->assertJsonPath('status', 'unavailable');
        }
        $this->postJson('/api/admin/executive-report/analysis', $body)->assertTooManyRequests();
    }
}
