<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
class PropertyAttribute extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'bedroom_count',
        'bathroom_count',
        'garage_count',
        'lot_area',
        'floor_area',
        'property_subtype_id',
    ];

    protected $casts = [
        'lot_area'          => 'decimal:2',
        'floor_area'         => 'decimal:2',
    ];

    public function subtype()
    {
        return $this->belongsTo(PropertySubtype::class, 'property_subtype_id');
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
            if ($model->usesSoftDeletes() && Auth::check()) {
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
