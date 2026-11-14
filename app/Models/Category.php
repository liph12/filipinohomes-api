<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Category extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
    ];

    /** Old category names (renamed 2026-11-14) → current, for old links/filters. */
    public const LEGACY_NAMES = [
        'Foreclosure' => 'Foreclosed',
        'For Assume' => 'Assume Balance',
    ];

    /** Map an old category name to its current one; anything else unchanged. */
    public static function currentName(string $name): string
    {
        return self::LEGACY_NAMES[trim($name)] ?? trim($name);
    }

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
