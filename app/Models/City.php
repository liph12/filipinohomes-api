<?php

namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class City extends Model
{
    protected $fillable = ['name', 'province_id', 'type', 'postalcode', 'totalsearch'];
 
    public function province()
    {
        return $this->belongsTo(Province::class, 'province_id');
    }
 
    public function barangays()
    {
        return $this->hasMany(Barangay::class, 'city_id');
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'city_id');
    }
}
