<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Listing extends Model
{
    use HasFactory;
    protected $fillable = [
        'code',
        'visibility',
        'name',
        'slug',
        'price',
        'featured_photo',
        'is_featured',
        'clicks',
        'property_id',
        'category_id',
        'agent_id',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'is_featured'  => 'boolean',
        'clicks'       => 'integer',
    ];

    protected static function booted()
    {
        // Generate slug BEFORE insert
        static::creating(function ($listing) {
            $baseSlug = Str::slug($listing->name);
            $slug = $baseSlug;
            $counter = 1;

            while (self::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            $listing->slug = $slug;
        });

        // Generate code AFTER insert (no redundancy)
        static::created(function ($listing) {
            $listing->updateQuietly([
                'code' => 'FH-' . now()->year . '-' . str_pad($listing->id, 10, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function scopeVisibleTo($query, $user = null)
    {
        if (!$user) {
            return $query->where('visibility', 'public');
        }

        return $query; // logged users see everything
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

}
