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
        'added_by'     => 'string',
        'created_by'   => 'integer',
        'updated_by'   => 'integer',
        'deleted_by'   => 'integer',
    ];

    public $timestamps = true;

    protected static function normalizeProjectText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^\s*,+\s*/', '', $value);
        $value = preg_replace('/\s+/', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }

    protected static function resolveUniqueName(?string $value, ?int $ignoreId = null): string
    {
        $base = static::normalizeProjectText($value) ?? 'Project';
        $candidate = $base;
        $counter = 2;

        while (static::withTrashed()
            ->whereRaw('LOWER(TRIM(name)) = ?', [Str::lower($candidate)])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $suffix = " ({$counter})";
            $candidate = Str::limit($base, 255 - strlen($suffix), '') . $suffix;
            $counter++;
        }

        return $candidate;
    }

    protected static function resolveUniqueSlug(?string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug((string) $value);
        if ($base === '') {
            $base = 'project';
        }

        $candidate = $base;
        $counter = 2;

        while (static::withTrashed()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $suffix = '-' . $counter;
            $candidate = Str::limit($base, 191 - strlen($suffix), '') . $suffix;
            $counter++;
        }

        return $candidate;
    }

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            $project->name = static::resolveUniqueName($project->name, $project->exists ? $project->id : null);
            $project->street = static::normalizeProjectText($project->street);
            $project->complete_address = static::normalizeProjectText($project->complete_address);
            $project->mapaddress = static::normalizeProjectText($project->mapaddress);

            if (!$project->complete_address && $project->mapaddress) {
                $project->complete_address = $project->mapaddress;
            }

            if (!$project->mapaddress && $project->complete_address) {
                $project->mapaddress = $project->complete_address;
            }

            if (!$project->exists || $project->isDirty('name') || empty($project->slug)) {
                $project->slug = static::resolveUniqueSlug($project->name, $project->exists ? $project->id : null);
            }
        });

        static::creating(function (Project $project) {
            if ($project->views === null) {
                $project->views = 0;
            }

            if (Auth::check()) {
                if (empty($project->added_by)) {
                    $project->added_by = Auth::user()?->email;
                }
                $project->created_by = Auth::id();
                $project->updated_by = Auth::id();
            }
        });

        static::updating(function (Project $project) {
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

    public function adder()
    {
        return $this->belongsTo(User::class, 'added_by', 'email');
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
