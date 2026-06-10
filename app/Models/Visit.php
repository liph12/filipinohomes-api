<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single anonymous/visitor session ping for acquisition analytics. Only
 * `created_at` is tracked (no updates), so `updated_at` is disabled.
 */
class Visit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'visitor_id',
        'channel',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer',
        'landing_path',
        'user_id',
        'ip',
    ];
}
