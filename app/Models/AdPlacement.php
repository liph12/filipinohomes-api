<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class AdPlacement extends Model implements Auditable
{
    use HasFactory;
    use LogsActivity;

    protected string $auditCategory = 'ads';

    protected $fillable = [
        'ad_id',
        'ad_section_id',
        'priority',
        'weight',
        'is_fixed',
    ];

    protected $casts = [
        'is_fixed' => 'boolean',
    ];

    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }

    public function section()
    {
        return $this->belongsTo(AdSection::class, 'ad_section_id');
    }
}
