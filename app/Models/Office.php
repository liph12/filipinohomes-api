<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class Office extends Model
{
    use HasFactory;

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
            $token = Str::lower(Str::random(10));
            $page->slug = 'tmp-' . $token;
        });

        static::created(function ($page) {
            $baseSlug = Str::slug($page->name);
            $finalSlug = $baseSlug;

            if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                $finalSlug = $baseSlug . '-' . $page->id;
            }

            $page->updateQuietly([
                'slug' => $finalSlug,
            ]);
        });

        static::updating(function ($page) {
            if ($page->isDirty('name')) {
                $baseSlug = Str::slug($page->name);
                $finalSlug = $baseSlug;

                if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                    $finalSlug = $baseSlug . '-' . $page->id;
                }

                $page->slug = $finalSlug;
            }
        });
    }
}