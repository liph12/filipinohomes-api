<?php

namespace App\Models;

use OwenIt\Auditing\Models\Audit as BaseAudit;

class Audit extends BaseAudit
{
    /**
     * Allow our custom columns to be mass-assigned alongside the package's
     * default attributes. The base model's $guarded = [] already permits
     * everything, but we expose the casts explicitly here.
     */
    protected $casts = [
        'old_values' => 'json',
        'new_values' => 'json',
    ];
}
