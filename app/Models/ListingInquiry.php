<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class ListingInquiry extends Model
{
    use HasFactory;
    protected $fillable = [
        'status',
        'client_id',
        'listing_id',
        'conversation_id'
    ];
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }
     public function listing()
    {
        return $this->belongsTo(listing::class, 'listing_id');
    }
    public function listingConversation()
    {
        return $this->belongsTo(ListingConversation::class, 'conversation_id');
    }

}
