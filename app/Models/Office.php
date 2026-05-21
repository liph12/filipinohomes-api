<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class Office extends Model implements Auditable
{
    use HasFactory;
    use LogsActivity;

    protected string $auditCategory = 'agents';

    protected $fillable = [
        'name',
        'slug',
        'title',
        'contact',
        'phone',
        'address',
        'photo',
        'geo_coordinates',
    ];

    protected $casts = [
        'photo' => 'array',
        'geo_coordinates' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($page) {
            // Use provided slug or derive from name
            $baseSlug = $page->slug
                ? Str::slug($page->slug)
                : Str::slug($page->name);

            // Fallback if name is also empty
            if (empty($baseSlug)) {
                $baseSlug = 'page-' . Str::lower(Str::random(6));
            }

            $finalSlug = $baseSlug;
            $counter = 1;

            // Keep incrementing until unique (can't use ID yet, so use counter)
            while (self::where('slug', $finalSlug)->exists()) {
                $finalSlug = $baseSlug . '-' . $counter++;
            }

            $page->slug = $finalSlug;
        });

        static::updating(function ($page) {
            $baseSlug = $page->isDirty('slug') && $page->slug
                ? Str::slug($page->slug)
                : Str::slug($page->name);

            $finalSlug = $baseSlug;
            $counter = 1;

            while (self::where('slug', $finalSlug)->where('id', '!=', $page->id)->exists()) {
                $finalSlug = $baseSlug . '-' . $counter++;
            }

            $page->slug = $finalSlug;
        });
    }
}
