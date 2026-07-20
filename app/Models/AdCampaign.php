<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use App\Casts\FlexibleDateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class AdCampaign extends Model implements Auditable
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected string $auditCategory = 'ads';

    protected $fillable = [
        'name',
        'advertiser',
        'status',
        'starts_at',
        'ends_at',
        'loop_duration',
    ];

    protected $casts = [
        'starts_at' => FlexibleDateTime::class,
        'ends_at' => FlexibleDateTime::class,
        'loop_duration' => 'integer',
    ];

    public function ads()
    {
        return $this->hasMany(Ad::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now('Asia/Manila'));
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now('Asia/Manila'));
            });
    }
}
