<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;
class Property extends Model implements Auditable
{
    use HasFactory;
    use SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'listings';
    protected array $auditLabelAttributes = ['name', 'address'];

    /**
     * Property rows are only created via ListingService::createListing, in
     * the same transaction as the parent Listing — the Listing's own
     * `created` audit captures that flow. Skip `created` here to avoid
     * duplicate "created" rows for a single listing creation. Updates and
     * deletes (status changes, cascade soft-deletes) are still audited.
     */
    protected $auditEvents = ['updated', 'deleted', 'restored'];

    protected $fillable = [
        'name',
        'project_id',
        'address',
        'status',
        'status_change_date',
        'status_remark',
        'ats_expiration_date',
        'ats_attachments',
        'ats_remarks',
        'agent_ats_remarks',
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
        'created_at',
        'photos_variants_generated_at',
    ];

    protected $casts = [
        'photos'     => 'array',
        'amenities'  => 'array',
        'geo_coordinates'  => 'array',
        'ats_attachments'  => 'array',
        'ats_remarks'       => 'string',
        'agent_ats_remarks' => 'string',
        'is_project' => 'boolean',
        'project_id' => 'integer',
        'status_change_date' => 'date',
        'ats_expiration_date' => 'date',
        'reviewed_by' => 'integer',
        'photos_variants_generated_at' => 'datetime',
    ];

    public function propertyAttribute()
    {
        return $this->belongsTo(PropertyAttribute::class, 'property_attribute_id');
    }

    public function furnishing()
    {
        return $this->belongsTo(Furnishing::class, 'furnishing_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    protected static function booted()
    {
        // Clear the stale variant flag when photos change (see Listing).
        static::updating(function ($model) {
            if ($model->isDirty('photos')) {
                $model->photos_variants_generated_at = null;
            }
        });

        static::saving(function ($model) {
            if (!empty($model->ats_expiration_date) && \Illuminate\Support\Carbon::parse($model->ats_expiration_date)->isPast()) {
                $model->ats_status = 'expired';
            }
        });

        // Auto-expire when a property is retrieved and ATS is due
        static::retrieved(function ($model) {
            try {
                if (!empty($model->ats_expiration_date)
                    && $model->ats_status !== 'expired'
                    && \Illuminate\Support\Carbon::parse($model->ats_expiration_date)->isPast()) {
                    // Avoid touching updated_at noisily
                    $originalTimestamps = $model->timestamps;
                    $model->timestamps = false;
                    $model->forceFill(['ats_status' => 'expired'])->saveQuietly();
                    $model->timestamps = $originalTimestamps;
                }
            } catch (\Throwable $e) {
                // Best-effort safeguard; do not block requests on failure
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
        return $this->hasOne(Listing::class, 'property_id')->publiclyListed();
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'property_id');
    }
}
