<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

class PageBuilder extends Model implements Auditable
{
    use HasFactory, SoftDeletes;
    use LogsActivity;

    protected string $auditCategory = 'content';

    // View-tracking counters — bumped on every page hit. Excluded so the
    // activity feed doesn't get spammed with click/impression updates.
    protected $auditExclude = ['clicks', 'impressions', 'updated_at'];

    protected $table = 'page_builder';

    protected $fillable = [
        'title',
        'slug',
        'seo_tags',
        'description',
        'about_me',
        'heading',
        'theme',
        'banner_settings',
        'featured_listings',
        'banner',
        'gallery',
        'flyers',
        'certificates',
        'awards',
        'video_url',
        'clicks',
        'impressions',
        'agent_id',

    ];

    protected $casts = [
        'seo_tags' => 'array',
        'theme' => 'array',
        'banner_settings' => 'array',
        'featured_listings' => 'array',
        'banner' => 'array',
        'gallery' => 'array',
        'flyers' => 'array',
        'certificates' => 'array',
        'awards' => 'array',
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
                $page->slug = 'tmp-'.$token;
            }
        });

        static::created(function ($page) {
            if (! Str::startsWith($page->slug, 'tmp-')) {
                $baseSlug = $page->slug;
            } else {
                $baseSlug = Str::slug($page->title);
            }

            $finalSlug = $baseSlug;

            if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                $finalSlug = $baseSlug.'-'.$page->id;
            }

            $page->updateQuietly([
                'slug' => $finalSlug,
            ]);
        });

        static::updating(function ($page) {
            // Slug permanence: the slug is set once at creation and stays put.
            // A title edit MUST NOT regenerate it (that would break the existing
            // public URL) — this mirrors the Listing model, where slugs are no
            // longer regenerated on title edits. The slug only changes when it's
            // explicitly edited (isDirty('slug')), which the page-builder form
            // never does, so in practice the URL is permanent after creation.
            if ($page->isDirty('slug') && $page->slug) {
                $baseSlug = Str::slug($page->slug);
                $finalSlug = $baseSlug;

                if (self::where('slug', $baseSlug)->where('id', '!=', $page->id)->exists()) {
                    $finalSlug = $baseSlug.'-'.$page->id;
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
