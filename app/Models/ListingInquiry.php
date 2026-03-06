<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ListingInquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'agent_id',
        'client_id',
        'status',
        'geo_coordinates',
    ];

       protected $casts = [
        'geo_coordinates' => 'array', 
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ListingConversation::class, 'inquiry_id')->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ListingConversation::class, 'inquiry_id')->latestOfMany();
    }

    public function unreadCountFor(int $userId): int
    {
        return $this->conversations()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $userId)
            ->count();
    }
}