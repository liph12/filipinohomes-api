<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chat extends Model
{
    use SoftDeletes;

    protected $fillable = ['type', 'user_id', 'type_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class, 'type_id');
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function activeConversation()
    {
        // Constrain the status INSIDE the one-of-many aggregate (closure form of
        // ofMany) rather than chaining whereIn(...)->latestOfMany(). The chained
        // form pushes the status filter onto the outer join, producing an
        // ambiguous `chat_id` self-join (SQLSTATE 1052) when eager-loaded across
        // many chats (e.g. GET /chats). This form keeps the same semantics —
        // the latest conversation among those statuses — with valid SQL.
        return $this->hasOne(Conversation::class)->ofMany(
            ['id' => 'max'],
            function ($query) {
                $query->whereIn('status', ['pending', 'accepted', 'rejected', 'closed']);
            },
        );
    }
}
