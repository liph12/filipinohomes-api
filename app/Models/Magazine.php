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

        // Step 1: Assign temporary slug before insert to avoid UNIQUE error
        static::creating(function ($page) {
            $token = Str::lower(Str::random(10));
            $page->slug = 'tmp-' . $token;
        });

        // Step 2: After insert, update slug to use ID
        static::created(function ($page) {
            $baseSlug = Str::slug($page->title);
            $finalSlug = $baseSlug . '-' . $page->id;

            $page->updateQuietly([
                'slug' => $finalSlug,
            ]);
        });

        // Step 3: Updating existing magazine if title changes
        static::updating(function ($page) {
            if ($page->isDirty('title')) {
                $page->slug = Str::slug($page->title) . '-' . $page->id;
            }
        });
    }
}