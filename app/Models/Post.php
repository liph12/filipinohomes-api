<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Auditing\LogsActivity;
use OwenIt\Auditing\Contracts\Auditable;

class Post extends Model implements Auditable
{
    use HasFactory;
    use LogsActivity;

    protected string $auditCategory = 'content';

    // View-tracking counter — bumped on every blog read. Excluded so the
    // activity feed doesn't get spammed with view updates.
    protected $auditExclude = ['views', 'impressions', 'updated_at'];
    protected array $auditLabelAttributes = ['title', 'slug'];

    protected $fillable = [
        'title',
        'slug',
        'content',
        'excerpt',
        'featured_image',
        'views',
        'category_id',
        'author_id',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    /**
     * Named human author (Person). Optional — older posts without an
     * author_id fall back to Organization authorship in the frontend
     * BlogPosting JSON-LD. Foreign key uses nullOnDelete so deleting
     * a staff writer keeps the post visible instead of cascading.
     */
    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}