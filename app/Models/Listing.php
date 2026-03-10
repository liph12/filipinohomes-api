<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class Listing extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'visibility', 'name', 'slug', 'price',
        'featured_photo', 'is_featured', 'clicks',
        'property_id', 'category_id', 'agent_id',
    ];

    protected $casts = [
        'price'          => 'decimal:2',
        'is_featured'    => 'boolean',
        'clicks'         => 'integer',
        'featured_photo' => 'array',
    ];

    protected static function booted()
    {
        // Generate slug BEFORE insert
        static::creating(function ($listing) {
            $baseSlug = Str::slug($listing->name);
            $slug     = $baseSlug;
            $counter  = 1;
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
        if (! $user) {
            return $query->where('listings.visibility', 'public');
        }

        // Admins see everything
        if ($user->role?->name === 'admin') {
            return $query;
        }

        // Agents see public listings + their own private ones
        $agentId = $user->agent?->id;

        return $query->where(function ($q) use ($agentId) {
            $q->where('listings.visibility', 'public')
              ->orWhere(function ($q2) use ($agentId) {
                  $q2->where('listings.visibility', 'private')
                     ->where('listings.agent_id', $agentId);
              });
        });
    }

    public function scopeFilter(Builder $query, Request $request): Builder
    {
        if ($search = $request->get('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('listings.name', 'like', "%{$search}%")
                  ->orWhereHas('property', fn ($sub) => $sub->where('address', 'like', "%{$search}%"));
            });
        }

        if ($categories = $request->get('categories')) {
            $cats = is_array($categories) ? $categories : explode(',', $categories);
            $query->whereHas('category', fn ($q) => $q->whereIn('name', $cats));
        }

        if ($subtypes = $request->get('subtypes')) {
            $ids = is_array($subtypes) ? $subtypes : explode(',', $subtypes);
            $query->whereHas('property.propertyAttribute.subtype', fn ($q) => $q->whereIn('id', $ids));
        }

        if ($priceMin = $request->get('price_min')) {
            $query->where('listings.price', '>=', $priceMin);
        }

        if ($priceMax = $request->get('price_max')) {
            $query->where('listings.price', '<=', $priceMax);
        }

        if ($request->filled('sqm_min')) {
            $query->whereHas('property.propertyAttribute', fn ($q) =>
                $q->whereRaw(
                    "GREATEST(COALESCE(lot_area, 0), COALESCE(floor_area, 0)) >= ?",
                    [$request->sqm_min]
                )
            );
        }

        if ($request->filled('sqm_max')) {
            $query->whereHas('property.propertyAttribute', fn ($q) =>
                $q->whereRaw(
                    "GREATEST(COALESCE(lot_area, 0), COALESCE(floor_area, 0)) <= ?",
                    [$request->sqm_max]
                )
            );
        }

        if ($request->filled('beds')) {
            $beds          = (int) $request->get('beds');
            $bedsCondition = $request->get('beds_condition', 'equal');
            $query->whereHas('property.propertyAttribute', fn ($q) =>
                $q->where('bedroom_count', match ($bedsCondition) {
                    'plus'  => '>=',
                    'minus' => '<=',
                    default => '=',
                }, $beds)
            );
        }

        if ($request->filled('baths')) {
            $baths          = (int) $request->get('baths');
            $bathsCondition = $request->get('baths_condition', 'equal');
            $query->whereHas('property.propertyAttribute', fn ($q) =>
                $q->where('bathroom_count', match ($bathsCondition) {
                    'plus'  => '>=',
                    'minus' => '<=',
                    default => '=',
                }, $baths)
            );
        }

        if ($furnishings = $request->get('furnishings')) {
            $ids = is_array($furnishings) ? $furnishings : explode(',', $furnishings);
            $query->whereHas('property', fn ($q) => $q->whereIn('furnishing_id', $ids));
        }

        if ($amenities = $request->get('amenities')) {
            $names = is_array($amenities) ? $amenities : explode(',', $amenities);
            $query->whereHas('property', function (Builder $q) use ($names) {
                foreach ($names as $name) {
                    $q->whereJsonContains('amenities', $name);
                }
            });
        }

        return $query;
    }

    public function scopeSorted(Builder $query, ?string $sortBy): Builder
    {
        return match ($sortBy) {
            'most-viewed' => $query->orderBy('clicks', 'desc'),
            'newest'      => $query->orderBy('created_at', 'desc'),
            'price-low'   => $query->orderBy('price', 'asc'),
            'price-high'  => $query->orderBy('price', 'desc'),
            'sqm-low', 'sqm-high' => $this->applySqmSort($query, $sortBy),
            default       => $query->orderByDesc('is_featured')->orderBy('clicks', 'desc'),
        };
    }

    private function applySqmSort(Builder $query, string $sortBy): Builder
    {
        $direction = $sortBy === 'sqm-low' ? 'asc' : 'desc';

        $joins = collect($query->getQuery()->joins ?? [])->pluck('table');
        if (! $joins->contains('properties')) {
            $query->leftJoin('properties', 'listings.property_id', '=', 'properties.id');
        }
        if (! $joins->contains('property_attributes')) {
            $query->leftJoin('property_attributes', 'properties.property_attribute_id', '=', 'property_attributes.id');
        }

        return $query
            ->orderByRaw("GREATEST(COALESCE(property_attributes.lot_area, 0), COALESCE(property_attributes.floor_area, 0)) {$direction}")
            ->select('listings.*');
    }

    public function property()  { return $this->belongsTo(Property::class); }
    public function category()  { return $this->belongsTo(Category::class); }
    public function agent()     { return $this->belongsTo(Agent::class); }
}