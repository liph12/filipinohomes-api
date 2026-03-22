<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = ['chat_id', 'status'];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'conversation_users')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
