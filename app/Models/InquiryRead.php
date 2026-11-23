<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One admin's read state for one Contact Inbox submission (read_at null = marked unread). */
class InquiryRead extends Model
{
    protected $fillable = ['inquiry_id', 'user_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
