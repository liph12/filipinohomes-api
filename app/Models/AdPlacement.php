<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdPlacement extends Model
{
    use HasFactory;

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
