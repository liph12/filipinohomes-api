<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Datetime cast that never throws on a malformed stored value.
 *
 * Laravel's built-in 'datetime' cast lets Carbon throw InvalidFormatException
 * the instant it hydrates a bad column value — e.g. MySQL's '0000-00-00
 * 00:00:00' zero-date under a permissive sql_mode, or otherwise unparseable
 * text. That kills the whole request with a 500 before any null-guard in a
 * resource (AdCampaignResource) ever runs. This cast degrades such a value to
 * null instead, so one bad row can't take down an entire paginated response.
 *
 * @implements CastsAttributes<Carbon|null, mixed>
 */
class FlexibleDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Zero-dates MySQL can return under a permissive sql_mode.
        if (str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
