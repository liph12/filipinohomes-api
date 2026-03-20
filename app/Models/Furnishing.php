<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Furnishing extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
    ];

    public function delete()
    {
        return false;
    }

    public function forceDelete()
    {
        return false;
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            return false;
        }

        return parent::save($options); 
    }

    public function update(array $attributes = [], array $options = [])
    {
        return false; 
    }
}
