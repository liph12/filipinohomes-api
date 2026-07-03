<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BuyerFormRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'buyer_form_id',
        'user_id',
        'full_name',
        'email',
        'home_address',
    ];

    public function buyerForm()
    {
        return $this->belongsTo(BuyerForm::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
