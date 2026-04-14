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
        'status_change_date',
        'status_remark',
        'ats_expiration_date',
        'ats_attachments',
        'ats_remarks',
        'ats_status',
        'reviewed_by',
        'photos',
        'amenities',
        'description',
        'address_id',
        'geo_coordinates',
        'is_project',
        'property_attribute_id',
        'furnishing_id',
        'updated_at',
        'created_at'
    ];

    protected $casts = [
        'photos'     => 'array',
        'amenities'  => 'array',
        'geo_coordinates'  => 'array',
        'ats_attachments'  => 'array',
        'ats_remarks'       => 'string',
        'is_project' => 'boolean',
        'status_change_date' => 'date',
        'ats_expiration_date' => 'date',
        'reviewed_by' => 'integer',
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
        static::saving(function ($model) {
            if (!empty($model->ats_expiration_date) && \Illuminate\Support\Carbon::parse($model->ats_expiration_date)->isPast()) {
                $model->ats_status = 'expired';
            }
        });

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

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function barangay()
    {
        return $this->belongsTo(Barangay::class, 'address_id');
    }

    public function nearbyFacility()
    {
        return $this->hasOne(NearbyFacility::class, 'property_id');
    }

    public function publicListing()
    {
        return $this->hasOne(Listing::class, 'property_id')->public();
    }
}
