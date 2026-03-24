<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PageBuilder extends Model
{
    use HasFactory;

    protected $table = 'page_builder';

    protected $fillable = [
        'title',
        'slug',
        'seo_tags',
        'description',
        'banner',
        'gallery',
        'youtube',
        'clicks',
        'impressions',
        'is_featured',
        'agent_id',

    ];

    protected $casts = [
        'seo_tags' => 'array',
        'banner' => 'array',
        'gallery' => 'array',
        'youtube' => 'array',
        'is_featured' => 'boolean',
        'clicks' => 'integer',
        'impressions' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($page) {
            $token = Str::lower(Str::random(10));
            $page->slug = 'tmp-' . $token;
        });

        static::created(function ($page) {
            $baseSlug = Str::slug($page->title);
            $finalSlug = $baseSlug;

            if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                $finalSlug = $baseSlug . '-' . $page->id;
            }

            $page->updateQuietly([
                'slug' => $finalSlug,
            ]);
        });

        static::updating(function ($page) {
            if ($page->isDirty('title')) {
                $baseSlug = Str::slug($page->title);
                $finalSlug = $baseSlug;

                if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                    $finalSlug = $baseSlug . '-' . $page->id;
                }

                $page->slug = $finalSlug;
            }
        });
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
