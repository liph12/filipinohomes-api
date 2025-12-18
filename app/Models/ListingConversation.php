<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListingConversation extends Model
{
    /** @use HasFactory<\Database\Factories\ListingConversationFactory> */
    use HasFactory;
      protected $fillable = [
        'messages',
        'client_status',
        'agent_status'
    ];
}
