<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'inquiry_id',
        'sender_id',
        'message',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'is_read' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $conversation) {
            if ($conversation->isDirty('read_at')) {
                $conversation->is_read = $conversation->read_at !== null;
            }
        });
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ListingInquiry::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}