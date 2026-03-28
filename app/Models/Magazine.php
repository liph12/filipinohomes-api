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
            if ($page->slug) {
                $page->slug = Str::slug($page->slug);
            } else {
                $token = Str::lower(Str::random(10));
                $page->slug = 'tmp-' . $token;
            }
        });

        static::created(function ($page) {
            if (!Str::startsWith($page->slug, 'tmp-')) {
                $baseSlug = $page->slug;
            } else {
                $baseSlug = Str::slug($page->title);
            }

            $finalSlug = $baseSlug;

            if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                $finalSlug = $baseSlug . '-' . $page->id;
            }

            $page->updateQuietly([
                'slug' => $finalSlug,
            ]);
        });

        static::updating(function ($page) {
            if ($page->isDirty('slug') && $page->slug) {
                $baseSlug = Str::slug($page->slug);
                $finalSlug = $baseSlug;

                if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                    $finalSlug = $baseSlug . '-' . $page->id;
                }

                $page->slug = $finalSlug;
            } elseif ($page->isDirty('title') && !$page->isDirty('slug')) {
                $baseSlug = Str::slug($page->title);
                $finalSlug = $baseSlug;

                if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                    $finalSlug = $baseSlug . '-' . $page->id;
                }

                $page->slug = $finalSlug;
            }
        });
    }
}