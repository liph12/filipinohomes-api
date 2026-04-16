<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $table = 'projects';
    /**
     * Allow mass-assignment for the expected project columns
     */
    protected $fillable = [
        'name',
        'slug',
        'prov_id',
        'city_id',
        'brgy_id',
        'street',
        'mapaddress',
        'latitude',
        'longitude',
        'complete_address',
        'date_updated',
        'added_by',
        'created_by',
        'updated_by',
        'deleted_by',
        'featured_photo',
        'photos_url',
    ];

    /**
     * Useful attribute casting (arrays, numbers, dates)
     */
    protected $casts = [
        'photos_url'   => 'array',
        'featured_photo'   => 'array',
        'latitude'     => 'float',
        'longitude'    => 'float',
        'date_added'   => 'date',
        'date_updated' => 'date',
        'prov_id'      => 'integer',
        'prop_type_id' => 'integer',
        'city_id'      => 'integer',
        'brgy_id'      => 'integer',
        'added_by'     => 'integer',
        'created_by'   => 'integer',
        'updated_by'   => 'integer',
        'deleted_by'   => 'integer',
    ];

    public $timestamps = true;

    protected static function booted(): void
    {
        // Before insert: set temporary slug and creator
        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $token = Str::lower(Str::random(10));
                $project->slug = 'tmp-' . $token;
            }
            if (Auth::check()) {
                if (empty($project->added_by)) {
                    $project->added_by = Auth::id();
                }
                $project->created_by = Auth::id();
                $project->updated_by = Auth::id();
            }
        });

        // After insert: compute final unique slug (append -{id} on conflict)
        static::created(function (Project $project) {
            $base = Str::slug((string) $project->name);
            if ($base === '') {
                $base = 'project';
            }
            $final = $base;
            $exists = static::query()->where('slug', $base)->where('id', '!=', $project->id)->exists();
            if ($exists) {
                $final = $base . '-' . $project->id;
            }
            if ($project->slug !== $final) {
                $project->updateQuietly(['slug' => $final]);
            }
        });

        // Before update: if name changed, recompute slug with possible -{id}
        static::updating(function (Project $project) {
            if ($project->isDirty('name')) {
                $base = Str::slug((string) $project->name);
                if ($base === '') {
                    $base = 'project';
                }
                $final = $base;
                $exists = static::query()->where('slug', $base)->where('id', '!=', $project->id)->exists();
                if ($exists) {
                    $final = $base . '-' . $project->id;
                }
                $project->slug = $final;
            }
            if (Auth::check()) {
                $project->updated_by = Auth::id();
            }
        });

        // Before soft delete: stamp deleted_by like Listing
        static::deleting(function (Project $project) {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses($project) ?: []);
            if ($usesSoftDeletes && Auth::check()) {
                $project->deleted_by = Auth::id();
                // Ensure attribute persists on soft delete
                $project->save();
            }
        });
    }

    public function properties()
    {
        return $this->hasMany(Property::class, 'project_id');
    }

    public function province()
    {
        return $this->belongsTo(Province::class, 'prov_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function barangay()
    {
        return $this->belongsTo(Barangay::class, 'brgy_id');
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
