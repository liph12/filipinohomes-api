<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class AppVersion extends Model implements Auditable
{
    use LogsActivity;

    protected string $auditCategory = 'system';
    protected array $auditLabelAttributes = ['version'];

    protected $fillable = [
        'version',
        'platform',
        'download_url',
        'notes',
        'is_latest',
        'released_at',
    ];

    protected $casts = [
        'is_latest'   => 'boolean',
        'released_at' => 'datetime',
    ];
}
