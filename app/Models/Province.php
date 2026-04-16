<?php

namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class Province extends Model
{
    protected $fillable = ['name', 'code'];
 
    public function cities()
    {
        return $this->hasMany(City::class, 'province_id');
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'prov_id');
    }
}
