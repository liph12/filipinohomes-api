<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Magazine extends Model
{
    protected $table = 'magazines';

    protected $fillable = [
        'title',
        'slug',
        'description',
        'cover_photo',
        'pdf_file',
        'publish_date',
    ];

    protected $casts = [
        'cover_photo' => 'array',
        'pdf_file' => 'array',
        'publish_date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($page) {
            // Use provided slug or derive from title
            $baseSlug = $page->slug
                ? Str::slug($page->slug)
                : Str::slug($page->title);

            // Fallback if title is also empty
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
                : Str::slug($page->title); 

            $finalSlug = $baseSlug;
            $counter = 1;

            while (self::where('slug', $finalSlug)->where('id', '!=', $page->id)->exists()) {
                $finalSlug = $baseSlug . '-' . $counter++;
            }

            $page->slug = $finalSlug;
        });
    }
}
