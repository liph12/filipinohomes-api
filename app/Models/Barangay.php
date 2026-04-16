<?php

namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class Barangay extends Model
{
    protected $fillable = ['name', 'city_id'];
 
    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'brgy_id');
    }
}
