<?php

namespace Tests\Feature;

use App\Services\OpenAI\CountCommandService;
use App\Services\OpenAI\ProjectCommandService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "How many condos are for sale in Lahug?" and "how many units does Mivesa have?" — the counting
 * is pure arithmetic over listing rows, so it is tested as such: every listing counts, the
 * narrowing follows what was asked, and the sentence says the numbers.
 */
class ListingCountTest extends TestCase
{
    /** Slim rows the Price Guide keeps per city, hand-made. */
    private function rows(): array
    {
        $fresh = Carbon::now()->subDays(3)->toDateTimeString();
        $old = Carbon::now()->subDays(90)->toDateTimeString();
        $r = fn (string $cat, string $type, ?string $tier, float $price, string $at, int $brgy = 1, string $subtype = '') => [
            'category' => $cat, 'type' => $type, 'subtype' => $subtype ?: ($tier ?? ''), 'tier' => $tier, 'price' => $price, 'listed_at' => $at,
            'barangay_id' => $brgy, 'barangay' => $brgy === 1 ? 'Lahug' : 'Mabolo', 'city' => $brgy === 1 ? 'Cebu City' : 'Mandaue City',
        ];

        return [
            $r('For Sale', 'Condominium', 'Studio', 2_500_000, $fresh),
            $r('For Sale', 'Condominium', 'Studio', 3_100_000, $old),
            $r('For Sale', 'Condominium', '1 Bedroom', 4_200_000, $old),
            $r('For Sale', 'Condominium', '2 Bedroom', 7_900_000, $fresh),
            $r('For Sale', 'Condominium', null, 5_000_000, $old, 1, 'Loft'),
            $r('For Rent', 'Condominium', 'Studio', 15_000, $old),
            $r('For Rent', 'Condominium', '1 Bedroom', 22_000, $fresh),
            $r('For Sale', 'House', '3 Bedroom', 12_000_000, $old, 1, 'House and Lot'),
            $r('For Sale', 'Land', null, 3_000_000, $old, 2, 'Residential Lot'),
            $r('For Rent', 'Commercial', null, 80_000, $old, 2, 'Warehouse'),
        ];
    }

    public function test_every_listing_is_counted_by_category_and_size(): void
    {
        $t = CountCommandService::tally($this->rows(), ['property_type' => '', 'tier' => '', 'category' => '']);

        $this->assertSame(['listings' => 10, 'sale_listings' => 7, 'rent_listings' => 3, 'new_30d' => 3], $t['totals']);
        $this->assertSame('Condominium', $t['types'][0]['type']);
        $this->assertSame(7, $t['types'][0]['listings']);

        $sale = $t['sections'][0];
        $this->assertSame('For Sale', $sale['category']);
        $this->assertSame(7, $sale['listings']);
        $this->assertSame(2_500_000.0, $sale['low']);
        $this->assertSame(12_000_000.0, $sale['high']);
        $this->assertSame(['Studio', '1 Bedroom', '2 Bedroom', '3 Bedroom', 'Other'], array_column($sale['tiers'], 'tier'));
        $this->assertSame([2, 1, 1, 1, 2], array_column($sale['tiers'], 'listings'), 'a size with one listing still counts');
        $this->assertSame(2, $sale['new_30d']);
    }

    public function test_the_count_narrows_to_the_type_size_and_category_asked(): void
    {
        $condos = CountCommandService::tally($this->rows(), ['property_type' => 'Condominium', 'tier' => '', 'category' => '']);
        $this->assertSame(7, $condos['totals']['listings']);
        $this->assertSame(5, $condos['totals']['sale_listings']);
        $this->assertSame(2, $condos['totals']['rent_listings']);

        $studiosForRent = CountCommandService::tally($this->rows(), ['property_type' => 'Condominium', 'tier' => 'Studio', 'category' => 'For Rent']);
        $this->assertSame(1, $studiosForRent['totals']['listings']);
        $this->assertCount(1, $studiosForRent['sections']);
        $this->assertSame(15_000.0, $studiosForRent['sections'][0]['median']);

        $houses = CountCommandService::tally($this->rows(), ['property_type' => 'House', 'tier' => '', 'category' => '']);
        $this->assertSame(1, $houses['totals']['listings']);

        $lots = CountCommandService::tally($this->rows(), ['property_type' => 'Lot', 'tier' => '', 'category' => '']);
        $this->assertSame(1, $lots['totals']['listings']);
    }

    public function test_the_sentence_says_the_numbers_and_the_sizes(): void
    {
        $tally = CountCommandService::tally($this->rows(), ['property_type' => 'Condominium', 'tier' => '', 'category' => '']);
        $text = CountCommandService::headline([
            'place' => ['label' => 'Lahug, Cebu City', 'city' => 'Cebu City'],
            'asked' => ['property_type' => 'Condominium', 'tier' => '', 'category' => ''],
            'totals' => $tally['totals'],
            'sections' => $tally['sections'],
            'city_totals' => null,
        ]);

        $this->assertSame(
            '**Lahug, Cebu City** has **7 condo listings** right now: **5 for sale** (₱2.5M – ₱7.9M; 2 studios, 1 one-bedroom, 1 two-bedroom, 1 size not stated) and **2 for rent** (₱15K – ₱22K/month; 1 studio, 1 one-bedroom). 3 of them were listed in the last 30 days.',
            $text,
        );
    }

    public function test_one_category_asked_reads_naturally(): void
    {
        $tally = CountCommandService::tally($this->rows(), ['property_type' => 'Condominium', 'tier' => '1 Bedroom', 'category' => 'For Rent']);
        $text = CountCommandService::headline([
            'place' => ['label' => 'Lahug, Cebu City', 'city' => 'Cebu City'],
            'asked' => ['property_type' => 'Condominium', 'tier' => '1 Bedroom', 'category' => 'For Rent'],
            'totals' => $tally['totals'],
            'sections' => $tally['sections'],
            'city_totals' => null,
        ]);

        $this->assertSame('**Lahug, Cebu City** has **1 one-bedroom condo for rent** right now (₱22K/month). It was listed in the last 30 days.', $text);
    }

    public function test_one_category_without_a_size_still_lists_the_sizes(): void
    {
        $tally = CountCommandService::tally($this->rows(), ['property_type' => 'Condominium', 'tier' => '', 'category' => 'For Rent']);
        $text = CountCommandService::headline([
            'place' => ['label' => 'Lahug, Cebu City', 'city' => 'Cebu City'],
            'asked' => ['property_type' => 'Condominium', 'tier' => '', 'category' => 'For Rent'],
            'totals' => $tally['totals'],
            'sections' => $tally['sections'],
            'city_totals' => null,
        ]);

        $this->assertSame('**Lahug, Cebu City** has **2 condos for rent** right now (₱15K – ₱22K/month; 1 studio, 1 one-bedroom). 1 of them was listed in the last 30 days.', $text);
    }

    public function test_nothing_in_the_barangay_points_at_the_city(): void
    {
        $text = CountCommandService::headline([
            'place' => ['label' => 'Busay, Cebu City', 'city' => 'Cebu City'],
            'asked' => ['property_type' => 'Condominium', 'tier' => '', 'category' => ''],
            'totals' => ['listings' => 0, 'sale_listings' => 0, 'rent_listings' => 0, 'new_30d' => 0],
            'sections' => [],
            'city_totals' => ['listings' => 7, 'sale_listings' => 5, 'rent_listings' => 2, 'new_30d' => 3],
        ]);

        $this->assertSame('I have no condos in **Busay, Cebu City** on Filipino Homes right now. **Cebu City** as a whole has **7 condos**: 5 for sale and 2 for rent.', $text);
    }

    public function test_a_project_counts_every_unit_by_size_and_says_so(): void
    {
        $slim = array_map(fn (array $r) => ['category' => $r['category'], 'tier' => $r['tier'], 'price' => $r['price']], array_slice($this->rows(), 0, 7));
        $mix = ProjectCommandService::unitMix($slim);

        $this->assertSame(['For Sale', 'For Rent'], array_column($mix, 'category'));
        $this->assertSame([5, 2], array_column($mix, 'listings'));
        $this->assertSame([2, 1, 1, 1], array_column($mix[0]['tiers'], 'listings'));
        $this->assertSame(2_500_000.0, $mix[0]['tiers'][0]['low']);
        $this->assertSame(3_100_000.0, $mix[0]['tiers'][0]['high']);

        $text = ProjectCommandService::mixSentence(['name' => 'Mivesa Garden Residences', 'totals' => ['listings' => 7], 'mix' => $mix]);
        $this->assertSame('**Mivesa Garden Residences** has **7 live listings** right now: **5 for sale** (2 studios, 1 one-bedroom, 1 two-bedroom, 1 size not stated) and **2 for rent** (1 studio, 1 one-bedroom).', $text);
    }

    public function test_the_inventory_counts_each_type_and_subtype_on_both_sides(): void
    {
        $inv = CountCommandService::inventory($this->rows());

        $this->assertSame(['Condominium', 'Studio', 3], [$inv[0]['type'], $inv[0]['subtype'], $inv[0]['listings']], 'most listings first');
        $this->assertSame(['listings' => 2, 'low' => 2_500_000.0, 'high' => 3_100_000.0], $inv[0]['sale']);
        $this->assertSame(['listings' => 1, 'low' => 15_000.0, 'high' => 15_000.0], $inv[0]['rent']);
        $warehouse = array_values(array_filter($inv, fn ($i) => $i['subtype'] === 'Warehouse'))[0];
        $this->assertNull($warehouse['sale'], 'nothing for sale: no band');
        $this->assertSame(1, $warehouse['rent']['listings']);
        $this->assertContains('House and Lot', array_column($inv, 'subtype'));
        $this->assertSame(7, count($inv), 'one row per type and subtype');
        $this->assertSame(array_sum(array_column($inv, 'listings')), count($this->rows()), 'every listing is in exactly one row');
    }

    public function test_barangays_say_where_the_listings_sit(): void
    {
        $by = CountCommandService::byBarangay($this->rows());

        $this->assertSame([
            ['barangay' => 'Lahug', 'listings' => 8, 'sale_listings' => 6, 'rent_listings' => 2],
            ['barangay' => 'Mabolo', 'listings' => 2, 'sale_listings' => 1, 'rent_listings' => 1],
        ], $by);
        $this->assertSame(['Lahug'], array_column(CountCommandService::byBarangay($this->rows(), 1), 'barangay'), 'the limit keeps the biggest');
        $this->assertSame('Barangay not stated', CountCommandService::byBarangay([['category' => 'For Sale', 'barangay' => null, 'price' => 1.0]])[0]['barangay']);
    }

    public function test_cities_say_where_a_provinces_listings_sit(): void
    {
        $this->assertSame([
            ['city' => 'Cebu City', 'listings' => 8, 'sale_listings' => 6, 'rent_listings' => 2],
            ['city' => 'Mandaue City', 'listings' => 2, 'sale_listings' => 1, 'rent_listings' => 1],
        ], CountCommandService::byCity($this->rows()));
    }

    public function test_a_province_is_read_from_its_name_or_from_the_word_province(): void
    {
        $provinces = [
            ['province_id' => 1, 'province' => 'Cebu', 'listings' => 2000, 'cities' => [['city_id' => 1, 'city' => 'Cebu City', 'listings' => 900]]],
            ['province_id' => 2, 'province' => 'Bohol', 'listings' => 40, 'cities' => [['city_id' => 9, 'city' => 'Tagbilaran City', 'listings' => 30]]],
            ['province_id' => 3, 'province' => 'Metro Manila', 'listings' => 120, 'cities' => []],
        ];
        $m = fn (array $asked, string $q, bool $known) => CountCommandService::matchProvince($provinces, $asked + ['province' => '', 'city' => '', 'barangay' => ''], $q, $known)['province'] ?? null;

        $this->assertSame('Bohol', $m(['province' => 'Bohol'], 'How many houses in Bohol?', false));
        $this->assertSame('Bohol', $m(['city' => 'Bohol'], 'How many houses in Bohol?', false), 'a province typed as the city, with no such city');
        $this->assertNull($m(['city' => 'Cebu'], 'How many condos in Cebu?', true), 'a known city wins');
        $this->assertSame('Cebu', $m(['city' => 'Cebu'], 'How many condos in the whole province of Cebu?', true), '"province" asks for all of it');
        $this->assertSame('Metro Manila', $m(['province' => 'NCR'], 'listings in NCR', false));
        $this->assertNull($m(['city' => 'Cebu City'], 'How many in Cebu City?', true));
        $this->assertNull($m(['city' => 'Atlantis'], 'How many in Atlantis?', false));
    }

    public function test_a_placeholder_price_is_not_a_listing_to_count(): void
    {
        $rows = $this->rows();
        $rows[] = ['category' => 'For Sale', 'type' => 'Commercial', 'subtype' => 'Building', 'tier' => null, 'price' => 150_000_000_000.0, 'listed_at' => '2026-01-01 00:00:00', 'barangay_id' => 1, 'barangay' => 'Lahug', 'city' => 'Cebu City'];
        $rows[] = ['category' => 'For Rent', 'type' => 'House', 'subtype' => 'House and Lot', 'tier' => null, 'price' => 99_000_000.0, 'listed_at' => '2026-01-01 00:00:00', 'barangay_id' => 1, 'barangay' => 'Lahug', 'city' => 'Cebu City'];

        $t = CountCommandService::tally($rows, ['property_type' => '', 'tier' => '', 'category' => '']);

        $this->assertSame(10, $t['totals']['listings'], 'a ₱150 billion sale and a ₱99 million a month rent are placeholders');
        $this->assertSame(12_000_000.0, $t['sections'][0]['high']);
    }

    public function test_sizes_are_left_out_when_no_size_is_known(): void
    {
        $this->assertSame('', CountCommandService::sizesPhrase([['tier' => 'Other', 'listings' => 4, 'low' => 1.0, 'high' => 2.0]]));
        $this->assertSame('3 four-bedroom or bigger', CountCommandService::sizesPhrase([['tier' => '4+ Bedroom', 'listings' => 3, 'low' => 1.0, 'high' => 2.0]]));
    }
}
