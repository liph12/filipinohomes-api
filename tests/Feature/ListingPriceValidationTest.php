<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Rules\PlausibleListingPrice;
use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesAuthTables;
use Tests\Concerns\CreatesListingFeedTables;
use Tests\TestCase;

/**
 * The price rule on the three write paths: a rent typed as 25, a sale typed as 3, a lot's price per sqm entered as its
 * price. In 'log' mode (the default) it only records them; in 'enforce' it refuses what can never be right and asks
 * for a confirmation on what is merely unusual. On an edit it speaks only when the price actually changed.
 */
class ListingPriceValidationTest extends TestCase
{
    use CreatesAuthTables;
    use CreatesListingFeedTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAuthTables();
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'agent']]);
        $this->createListingFeedTables();
    }

    /** @return string|null the first message the rule gives for these inputs (null = accepted) */
    private function check(array $data, ?Listing $existing = null): ?string
    {
        $v = Validator::make($data, ['price' => ['required', 'numeric', new PlausibleListingPrice($existing)]]);

        return $v->fails() ? $v->errors()->first('price') : null;
    }

    private function mode(string $mode): void
    {
        ListingFeedSwitch::set(ListingFeedSwitch::VALIDATION, $mode);
    }

    public function test_it_defaults_to_log_which_records_and_lets_everything_through(): void
    {
        Log::spy();

        $this->assertNull($this->check(['price' => 25, 'category_id' => 2, 'property_type_id' => 2]));

        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => $message === 'listing.price.implausible'
            && $context['mode'] === 'log' && $context['reason'] === 'thousands_shorthand' && $context['suggested'] === 25_000.0);
    }

    public function test_off_does_nothing(): void
    {
        $this->mode('off');
        Log::spy();

        $this->assertNull($this->check(['price' => 25, 'category_id' => 2, 'property_type_id' => 2]));
        Log::shouldNotHaveReceived('info');
    }

    public function test_a_log_that_cannot_be_written_never_stops_a_save_and_never_changes_a_verdict(): void
    {
        Log::shouldReceive('info')->andThrow(new \RuntimeException('The stream or file could not be opened'));

        // log mode: the save goes through even though recording it failed.
        $this->assertNull($this->check(['price' => 25, 'category_id' => 2, 'property_type_id' => 2]));

        // enforce mode: the verdict is the same as with a working log.
        $this->mode('enforce');
        $this->assertStringContainsString('₱25,000', $this->check(['price' => 25, 'category_id' => 2, 'property_type_id' => 2]));
    }

    public function test_enforce_refuses_what_can_never_be_right_and_says_what_was_probably_meant(): void
    {
        $this->mode('enforce');

        $this->assertStringContainsString('₱25,000', $this->check(['price' => 25, 'category_id' => 2, 'property_type_id' => 2]));
        $this->assertStringContainsString('₱3,000,000', $this->check(['price' => 3, 'category_id' => 1, 'property_type_id' => 1]));
        $this->assertStringContainsString('₱210,000', $this->check(['price' => 700, 'category_id' => 1, 'property_type_id' => 3, 'lot_area' => 300]));
        $this->assertStringContainsString('cannot be right', $this->check(['price' => 110, 'category_id' => 2, 'property_type_id' => 2]));
        // A confirmation does not rescue a price that can never be right.
        $this->assertNotNull($this->check(['price' => 25, 'category_id' => 2, 'property_type_id' => 2, 'price_confirmed' => true]));
    }

    public function test_enforce_asks_for_a_confirmation_on_an_unusual_price_and_accepts_it(): void
    {
        $this->mode('enforce');
        $lot = ['price' => 85_000, 'category_id' => 1, 'property_type_id' => 3, 'lot_area' => 500];

        $this->assertStringContainsString('confirm', (string) $this->check($lot));
        $this->assertNull($this->check($lot + ['price_confirmed' => true]));
        $this->assertNull($this->check($lot + ['price_confirmed' => '1']));

        $this->assertNotNull($this->check(['price' => 68_000_000, 'category_id' => 2, 'property_type_id' => 1]));
        $this->assertNull($this->check(['price' => 68_000_000, 'category_id' => 2, 'property_type_id' => 1, 'price_confirmed' => true]));
    }

    public function test_enforce_accepts_an_ordinary_price_without_a_word(): void
    {
        $this->mode('enforce');
        Log::spy();

        $this->assertNull($this->check(['price' => 25_000, 'category_id' => 2, 'property_type_id' => 2]));
        $this->assertNull($this->check(['price' => 1_350_000, 'category_id' => 1, 'property_type_id' => 1]));
        Log::shouldNotHaveReceived('info');
    }

    public function test_an_edit_speaks_only_when_the_price_or_the_category_changed(): void
    {
        $this->mode('enforce');
        [$agent] = $this->feedAgent();
        $id = $this->feedListing(['agent' => $agent, 'price' => 25, 'category' => 2, 'type' => 'Condominium']);
        $listing = Listing::findOrFail($id);

        // The agent fixes a typo in the title and sends the unchanged price along: not held up by it.
        $this->assertNull($this->check(['price' => 25, 'name' => 'New title'], $listing));
        // The same price on an edit that changes the price elsewhere is still spoken about.
        $this->assertNotNull($this->check(['price' => 30, 'category_id' => 2], $listing));
        // Changing the category re-judges the price against the new category.
        $this->assertNotNull($this->check(['price' => 25, 'category_id' => 1], $listing));
        // An agent who changes it to a good price is fine.
        $this->assertNull($this->check(['price' => 25_000], $listing));
    }

    public function test_an_edit_takes_the_category_and_type_from_the_listing_when_the_request_omits_them(): void
    {
        $this->mode('enforce');
        [$agent] = $this->feedAgent();
        $id = $this->feedListing(['agent' => $agent, 'price' => 1_500_000, 'category' => 2, 'type' => 'Condominium']);
        $listing = Listing::findOrFail($id);

        // It is a rent of a condo, so 99 reads as ₱99,000 a month.
        $this->assertStringContainsString('₱99,000', $this->check(['price' => 99], $listing));
    }
}
