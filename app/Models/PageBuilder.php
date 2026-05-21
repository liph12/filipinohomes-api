<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class PageBuilder extends Model implements Auditable
{
    use HasFactory, SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'content';

    protected $table = 'page_builder';

    protected $fillable = [
        'title',
        'slug',
        'seo_tags',
        'description',
        'banner',
        'gallery',
        'video_url',
        'clicks',
        'impressions',
        'agent_id',

    ];

    protected $casts = [
        'seo_tags' => 'array',
        'banner' => 'array',
        'gallery' => 'array',
        'video_url' => 'array',
        'clicks' => 'integer',
        'impressions' => 'integer',
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

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
