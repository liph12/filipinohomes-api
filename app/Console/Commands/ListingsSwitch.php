<?php

namespace App\Console\Commands;

use App\Services\Listing\ListingFeedSwitch;
use Illuminate\Console\Command;

/**
 * Show or change the switches for how listings are ordered and how their prices are judged. Each is a row of
 * the `settings` table, so a change reaches every host within half a minute and needs no deploy; setting one
 * back to its default undoes it.
 *
 *   php artisan listings:switch                          show every switch and its value
 *   php artisan listings:switch order groups             available + real price first, then "Price on request", then sold
 *   php artisan listings:switch order legacy             back to newest update first
 *   php artisan listings:switch price-guard on           judge prices (price_status, price filters, price bands)
 *   php artisan listings:switch validation enforce       refuse a price that can never be right on the write paths
 *   php artisan listings:switch picker fair              the rotation stamps only listings a buyer can act on
 *   php artisan listings:switch lastmod on               the listing sitemaps carry the real last-change date
 *   php artisan listings:switch dial incomplete_every 3  a rotation number (see config/listings.php)
 */
class ListingsSwitch extends Command
{
    protected $signature = 'listings:switch {name? : order | price-guard | validation | picker | lastmod | dial} {value? : the new value} {extra? : for "dial": the number}';

    protected $description = 'Show or change the listing order / price switches (stored in the settings table)';

    /** @var array<string, array{key: string, values: list<string>, default: string, what: string}> */
    private const SWITCHES = [
        'order' => ['key' => ListingFeedSwitch::ORDER, 'values' => ['legacy', 'groups'], 'default' => 'legacy', 'what' => 'result page order'],
        'price-guard' => ['key' => ListingFeedSwitch::GUARD, 'values' => ['off', 'on'], 'default' => 'off', 'what' => 'judge prices (price_status, price filters)'],
        'validation' => ['key' => ListingFeedSwitch::VALIDATION, 'values' => ['off', 'log', 'enforce'], 'default' => 'log', 'what' => 'price check on create / edit'],
        'picker' => ['key' => ListingFeedSwitch::PICKER, 'values' => ['id', 'fair'], 'default' => 'id', 'what' => 'which listings the hourly rotation stamps'],
        'lastmod' => ['key' => ListingFeedSwitch::LASTMOD, 'values' => ['off', 'on'], 'default' => 'off', 'what' => 'sitemap lastmod = real last-change date'],
    ];

    private const DIALS = ['batch', 'incomplete_every', 'heldback_every', 'agent_idle_days'];

    public function handle(): int
    {
        $name = $this->argument('name');

        if ($name === null) {
            return $this->show();
        }

        if ($name === 'dial') {
            return $this->setDial((string) $this->argument('value'), $this->argument('extra'));
        }

        if (! isset(self::SWITCHES[$name])) {
            $this->error("Unknown switch '{$name}'. Choose one of: ".implode(', ', array_keys(self::SWITCHES)).', dial.');

            return self::FAILURE;
        }

        $spec = self::SWITCHES[$name];
        $value = $this->argument('value');
        if ($value === null) {
            $this->line("{$name}: ".$this->current($name).'   (one of: '.implode(' | ', $spec['values']).')');

            return self::SUCCESS;
        }
        if (! in_array($value, $spec['values'], true)) {
            $this->error("'{$value}' is not a value for {$name}. Use one of: ".implode(' | ', $spec['values']).'.');

            return self::FAILURE;
        }

        ListingFeedSwitch::set($spec['key'], $value);
        $this->info("{$name} is now {$value}.");

        return $this->show();
    }

    private function show(): int
    {
        $rows = [];
        foreach (self::SWITCHES as $name => $spec) {
            $current = $this->current($name);
            $rows[] = [$name, $current.($current === $spec['default'] ? '  (default)' : ''), implode(' | ', $spec['values']), $spec['what']];
        }
        foreach (self::DIALS as $dial) {
            $rows[] = ["dial {$dial}", (string) ListingFeedSwitch::dial($dial), 'a whole number, 1 or more', 'rotation: '.str_replace('_', ' ', $dial)];
        }
        $this->table(['switch', 'now', 'values', 'what it does'], $rows);
        $this->line('Prices confirmed by their agents: '.count(ListingFeedSwitch::approvedPrices()).'.');

        return self::SUCCESS;
    }

    private function current(string $name): string
    {
        return match ($name) {
            'order' => ListingFeedSwitch::order(),
            'price-guard' => ListingFeedSwitch::priceGuard() ? 'on' : 'off',
            'validation' => ListingFeedSwitch::priceValidation(),
            'picker' => ListingFeedSwitch::rotationPicker(),
            'lastmod' => ListingFeedSwitch::sitemapLastmod() ? 'on' : 'off',
        };
    }

    private function setDial(string $dial, mixed $number): int
    {
        if (! in_array($dial, self::DIALS, true) || ! is_numeric($number) || (int) $number < 1) {
            $this->error('Usage: listings:switch dial {'.implode('|', self::DIALS).'} {a whole number, 1 or more}');

            return self::FAILURE;
        }

        ListingFeedSwitch::set("listings.rotation.{$dial}", (string) (int) $number);
        $this->info("dial {$dial} is now ".(int) $number.'.');

        return $this->show();
    }
}
