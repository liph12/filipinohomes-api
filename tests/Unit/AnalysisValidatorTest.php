<?php

namespace Tests\Unit;

use App\Services\Reports\Executive\Analysis\AnalysisValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The AI analysis may only state numbers the report computed. Every refusal
 * below is a way a model could slip in a number of its own.
 */
class AnalysisValidatorTest extends TestCase
{
    private const FACTS = [
        'meta' => ['range' => ['start' => '2026-11-01', 'end' => '2026-11-10'], 'previous' => ['start' => '2026-10-01', 'end' => '2026-10-10']],
        'facts' => [
            'agents_dormant_30d' => ['label' => 'Dormant 30+ days', 'value' => 42, 'previous' => null, 'change_pct' => null, 'sample' => null, 'unit' => 'count', 'as_of' => 'now', 'partial' => false, 'metric' => 'agents.dormant_30d', 'filters' => []],
            'listings_added' => ['label' => 'Listings added', 'value' => 1204, 'previous' => 1100, 'change_pct' => 9.5, 'sample' => null, 'unit' => 'count', 'as_of' => 'range', 'partial' => false, 'metric' => 'listings.added', 'filters' => []],
            'listings_ppsqm_rent' => ['label' => 'Median ₱/sqm (rent)', 'value' => null, 'previous' => null, 'change_pct' => null, 'sample' => 0, 'unit' => 'php_per_sqm', 'as_of' => 'range', 'partial' => false, 'metric' => 'listings.ppsqm_rent', 'filters' => []],
            'town_1_deals' => ['label' => 'Deals in Cebu City', 'name' => 'Cebu City', 'value' => 7, 'previous' => null, 'change_pct' => null, 'sample' => null, 'unit' => 'count', 'as_of' => 'range', 'partial' => false, 'metric' => 'deals.total', 'filters' => ['city_id' => 10]],
        ],
    ];

    private static function answer(array $texts, string $headline = 'Listings grew to {listings_added}.'): array
    {
        return [
            'headline' => $headline,
            'bullets' => array_map(fn ($t) => ['text' => $t, 'fact_ids' => [], 'kind' => 'watch'], $texts),
        ];
    }

    public function test_a_clean_answer_passes_and_collects_its_fact_ids(): void
    {
        $result = (new AnalysisValidator)->validate(self::answer([
            '{agents_dormant_30d} agents have not opened FH in a month.',
            'Listings rose {listings_added.change}, from {listings_added.prev} to {listings_added}.',
            '{town_1_deals.name} led with {town_1_deals} deals.',
        ]), self::FACTS);

        $this->assertCount(3, $result['bullets']);
        $this->assertSame(0, $result['dropped']);
        $this->assertSame(['town_1_deals'], $result['bullets'][2]['fact_ids']);
        $this->assertSame('Listings grew to {listings_added}.', $result['headline']);
    }

    /** [a bullet that must be dropped] */
    public static function invented(): array
    {
        return [
            'a typed number' => ['42 agents have not opened FH in a month.'],
            'a percent sign' => ['Listings rose 9% to {listings_added}.'],
            'a spelled-out count' => ['Three teams carried {listings_added} listings.'],
            '"twice"' => ['Listings were twice {listings_added.prev}.'],
            '"zero"' => ['Zero deals closed while {listings_added} listings arrived.'],
            'an unknown fact' => ['{agents_idle} agents did nothing.'],
            'a previous value that does not exist' => ['Dormant agents went from {agents_dormant_30d.prev}.'],
            'a change that does not exist' => ['Dormant agents moved {agents_dormant_30d.change}.'],
            'a value with nothing to show' => ['Rent runs {listings_ppsqm_rent} a square meter.'],
            'a name that is not there' => ['{listings_added.name} posted the most.'],
            'a broken placeholder' => ['{Listings_Added} listings arrived.'],
            'markdown' => ['**{listings_added}** listings arrived.'],
            'a link' => ['See https://filipinohomes.com for {listings_added} listings.'],
        ];
    }

    #[DataProvider('invented')]
    public function test_a_bullet_with_its_own_number_is_dropped(string $bad): void
    {
        $result = (new AnalysisValidator)->validate(self::answer([
            '{agents_dormant_30d} agents have not opened FH in a month.',
            $bad,
            '{town_1_deals.name} led with {town_1_deals} deals.',
        ]), self::FACTS);

        $this->assertCount(2, $result['bullets']);
        $this->assertSame(1, $result['dropped']);
    }

    public function test_fewer_than_two_good_bullets_is_no_analysis(): void
    {
        $this->assertNull((new AnalysisValidator)->validate(self::answer([
            '{agents_dormant_30d} agents have not opened FH in a month.',
            '42 agents are dormant.',
            'Sales rose 12%.',
        ]), self::FACTS));
    }

    public function test_a_bad_headline_is_dropped_without_losing_the_bullets(): void
    {
        $result = (new AnalysisValidator)->validate(self::answer([
            '{agents_dormant_30d} agents have not opened FH in a month.',
            '{town_1_deals.name} led with {town_1_deals} deals.',
        ], 'A record 1,204 listings!'), self::FACTS);

        $this->assertNull($result['headline']);
        $this->assertCount(2, $result['bullets']);
    }

    public function test_render_turns_placeholders_into_drillable_segments(): void
    {
        $segments = (new AnalysisValidator)->render('{town_1_deals.name} closed {town_1_deals}, listings {listings_added.change} from {listings_added.prev}.', self::FACTS);

        $this->assertSame(['text', 'text', 'metric', 'text', 'change', 'text', 'metric', 'text'], array_column($segments, 'type'));
        $this->assertSame('Cebu City', $segments[0]['text']);
        $this->assertSame(['7', 'deals.total', ['city_id' => 10]], [$segments[2]['text'], $segments[2]['metric'], (array) $segments[2]['filters']]);
        $this->assertSame('2026-11-01', $segments[2]['range']['start']);
        $this->assertSame(['+9.5%', 'up', 'up'], [$segments[4]['text'], $segments[4]['direction'], $segments[4]['better']]);
        $this->assertSame(['1,100', '2026-10-01'], [$segments[6]['text'], $segments[6]['range']['start']]);
    }

    public function test_values_are_written_the_way_the_report_writes_them(): void
    {
        $this->assertSame('₱98,500', AnalysisValidator::format(98500.4, 'php_per_sqm'));
        $this->assertSame('50 min', AnalysisValidator::format(50, 'minutes'));
        $this->assertSame('2h 5m', AnalysisValidator::format(125, 'minutes'));
        $this->assertSame('1.5 days', AnalysisValidator::format(2160, 'minutes'));
        $this->assertSame('1,204', AnalysisValidator::format(1204, 'count'));
        $this->assertSame('—', AnalysisValidator::format(null, 'count'));
        $this->assertSame('−8.5%', AnalysisValidator::pct(-8.5));
        $this->assertSame('+800%', AnalysisValidator::pct(800.0));
    }
}
