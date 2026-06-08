<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    protected $fillable = [
        'created_by',
        'kind',
        'title',
        'body',
        'data',
        'audience',
        'recipients_count',
        'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'audience' => 'array',
        'sent_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Per-recipient feed rows fanned out for this announcement. */
    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }
}
