<?php

namespace App\Rules;

use App\Models\Category;
use App\Models\Listing;
use App\Services\Listing\ListingFeedSwitch;
use App\Services\Listing\ListingPriceRules;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stops a price that cannot be an asking price from being saved: a rent typed as 25 for ₱25,000, a house typed as
 * 3 for ₱3,000,000, a lot's price per sqm entered as its price. Used on the three places a listing's price is written
 * (create, the full edit, the quick edit); the same judgement the result pages use ({@see ListingPriceRules}).
 *
 * What it does depends on the `listings.price_validation` switch:
 *   off      nothing
 *   log      records what it WOULD refuse and lets the save through (the default: see the numbers before enforcing)
 *   enforce  refuses a price that can never be right (under ₱1,000 a month, under ₱10,000 for a sale), with the
 *            likely figure in the message; refuses an unusual-but-possible price (a ₱50,000 lot, a rent over
 *            ₱10M) unless the request carries `price_confirmed`
 *
 * On an edit it only speaks when the price (or the category) actually changed, so an agent fixing a typo in the
 * title is never held up by a price they did not touch.
 */
class PlausibleListingPrice implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly ?Listing $existing = null) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $mode = ListingFeedSwitch::priceValidation();
        if ($mode === 'off' || ! is_numeric($value) || $this->unchanged((float) $value)) {
            return;
        }

        $categoryName = $this->categoryName();
        // Most prices are fine: decide that with the cheap inputs before looking up anything else.
        if (ListingPriceRules::classify($value, $categoryName)['status'] === 'ok') {
            return;
        }

        $verdict = ListingPriceRules::classify($value, $categoryName, $this->typeName(), $this->lotArea());
        if ($verdict['status'] === 'ok') {
            return;
        }

        $confirmed = filter_var($this->data['price_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        try {
            Log::info('listing.price.implausible', [
                'mode' => $mode,
                'listing_id' => $this->existing?->id,
                'price' => (float) $value,
                'category' => $categoryName,
                'reason' => $verdict['reason'],
                'suggested' => $verdict['suggested'],
                'confirmable' => $verdict['confirmable'],
                'confirmed' => $confirmed,
            ]);
        } catch (\Throwable) {
            // Recording is a courtesy: a log that cannot be written must never stop a save, least of all in log mode.
        }

        if ($mode === 'log') {
            return;
        }

        if (! $verdict['confirmable']) {
            $fail($this->mustFixMessage($verdict['reason'], $verdict['suggested'], (float) $value));

            return;
        }
        if (! $confirmed) {
            $fail('This price is outside the usual range for a '.strtolower((string) $categoryName).'. If it is correct, confirm it and save again.');
        }
    }

    private function mustFixMessage(?string $reason, ?float $suggested, float $value): string
    {
        $peso = fn (float $n): string => '₱'.number_format($n);

        return match ($reason) {
            ListingPriceRules::REASON_THOUSANDS => sprintf('%s a month looks like %s. Enter the full monthly rent (for example %d).', $peso($value), $peso((float) $suggested), (int) $suggested),
            ListingPriceRules::REASON_MILLIONS => sprintf('%s looks like %s. Enter the full price (for example %d).', $peso($value), $peso((float) $suggested), (int) $suggested),
            ListingPriceRules::REASON_PER_SQM => sprintf('This looks like a price per sqm. Enter the total price of the lot (about %s).', $peso((float) $suggested)),
            ListingPriceRules::REASON_NO_PRICE => 'Please enter a price.',
            default => 'A price this low cannot be right. Enter the full price.',
        };
    }

    /** On an edit: is this the price (and category) the listing already has? */
    private function unchanged(float $price): bool
    {
        if ($this->existing === null) {
            return false;
        }
        $categoryChanged = isset($this->data['category_id'])
            && (int) $this->data['category_id'] !== (int) $this->existing->category_id;

        return ! $categoryChanged && abs((float) $this->existing->price - $price) < 0.005;
    }

    private function categoryName(): ?string
    {
        $id = $this->data['category_id'] ?? $this->existing?->category_id;

        return $id ? Category::query()->whereKey($id)->value('name') : null;
    }

    private function typeName(): ?string
    {
        $typeId = $this->data['property_type_id']
            ?? $this->existing?->property?->propertyAttribute?->subtype?->property_type_id;

        return $typeId ? DB::table('property_types')->where('id', $typeId)->value('name') : null;
    }

    private function lotArea(): mixed
    {
        return $this->data['lot_area'] ?? $this->existing?->property?->propertyAttribute?->lot_area;
    }
}
