<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
class Property extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $fillable = [
        'name',
        'address',
        'status',
        'photos',
        'amenities',
        'description',
        'address_id',
        'geo_coordinates',
        'is_project',
        'property_attribute_id',
        'furnishing_id',
    ];

    protected $casts = [
        'photos'     => 'array',
        'amenities'  => 'array',
        'geo_coordinates'  => 'array',
        'is_project' => 'boolean',
    ];

    public function propertyAttribute()
    {
        return $this->belongsTo(PropertyAttribute::class, 'property_attribute_id');
    }

    public function furnishing()
    {
        return $this->belongsTo(Furnishing::class, 'furnishing_id');
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model) {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses($model) ?: []);
            if ($usesSoftDeletes && Auth::check()) {
                $model->deleted_by = Auth::id();
                $model->save();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
