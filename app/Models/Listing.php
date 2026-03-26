<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use App\Models\Province;
class Listing extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'visibility', 'name', 'slug', 'price',
        'featured_photo', 'is_featured', 'clicks','impressions',
        'property_id', 'category_id', 'agent_id', 'seo_tags','created_at','updated_at'
    ];

    protected $casts = [
        'price'          => 'decimal:2',
        'is_featured'    => 'boolean',
        'clicks'         => 'integer',
        'featured_photo' => 'array',
        'seo_tags'       => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($listing) {
            $token = Str::lower(Str::random(10));
            $listing->slug = 'tmp-' . $token;
            $listing->code = 'TMP-' . Str::upper($token);
        });

        static::created(function ($listing) {
            $baseSlug = Str::slug($listing->name);
            $finalSlug = $baseSlug;

            if (self::withTrashed()->where('slug', $baseSlug)->where('id', '!=', $listing->id)->exists()) {
                $finalSlug = $baseSlug . '-' . $listing->id;
            }

            $address = optional($listing->property)->address;
            $provinceCode = self::provinceCodeFromAddress($address);

            $listing->updateQuietly([
                'slug' => $finalSlug,
                'code' => $provinceCode . '-' . str_pad((string) $listing->id, 4, '0', STR_PAD_LEFT),
            ]);
        });

        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model) {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses($model) ?: []);
            if ($usesSoftDeletes && Auth::check()) {
                $model->deleted_by = Auth::id();
                $model->save();
            }
        });
    }

    private static function provinceCodeFromAddress(?string $address): string
    {
        $parts = $address
            ? array_values(array_filter(array_map('trim', explode(',', $address))))
            : [];
        if (empty($parts)) {
            return 'BLK';
        }

        $country = $parts[count($parts) - 1];
        $province = strcasecmp($country, 'Philippines') === 0 && count($parts) > 2
            ? $parts[count($parts) - 3]
            : $country;

        try {
            $provinceName = trim($province);
            if ($provinceName !== '') {
                $provinceModel = Province::whereRaw('LOWER(name) = ?', [strtolower($provinceName)])->first();
                if (! $provinceModel) {
                    $provinceModel = Province::where('name', 'like', "%{$provinceName}%")->first();
                }

                if ($provinceModel && ! empty($provinceModel->code)) {
                    return Str::upper(substr($provinceModel->code, 0, 3));
                }
            }
        } catch (\Throwable $e) {
        }

        return 'NON';
    }

    public function scopeFilter(Builder $query, Request $request): Builder
    {
        if ($search = $request->input('search')) {
            $array_words = explode(' ', $search);
            $address = $request->input('address');

            $query->where(function ($sub) use ($array_words) {
                foreach ($array_words as $w) {
                    $lower = strtolower($w);
                    $sub->where('name', 'LIKE', "%{$lower}%");
                }
            })->whereHas('property', function($q) use($array_words, $address){
                $addr = explode(' ', $address);

                $q->where(function ($q) use ($addr) {
                    foreach ($addr as $w) {
                        $q->where(function ($sub) use ($w) {
                            $sub->where('address', 'LIKE', "%{$w}%");
                        });
                    }
                })->where(function ($q) use ($array_words) {
                    foreach ($array_words as $w) {
                        $q->where(function ($sub) use ($w) {
                            $sub->where('description', 'LIKE', "%{$w}%");
                        });
                    }
                });
            });
        }

        if ($categories = $request->input('categories')) {
            $cats = is_array($categories) ? $categories : explode(',', $categories);
            $query->whereHas('category', fn ($q) => $q->whereIn('name', $cats));
        }

        if ($subtypes = $request->input('subtypes')) {
            $ids = is_array($subtypes) ? $subtypes : explode(',', $subtypes);
            $query->whereHas('property.propertyAttribute.subtype', fn ($q) => $q->whereIn('id', $ids));
        }

        if ($priceMin = $request->input('price_min')) {
            $query->where('listings.price', '>=', $priceMin);
        }

        if ($priceMax = $request->input('price_max')) {
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
            $beds          = (int) $request->input('beds');
            $bedsCondition = $request->input('beds_condition', 'equal');
            $query->whereHas('property.propertyAttribute', fn ($q) =>
                $q->where('bedroom_count', match ($bedsCondition) {
                    'plus'  => '>=',
                    'minus' => '<=',
                    default => '=',
                }, $beds)
            );
        }

        if ($request->filled('baths')) {
            $baths          = (int) $request->input('baths');
            $bathsCondition = $request->input('baths_condition', 'equal');
            $query->whereHas('property.propertyAttribute', fn ($q) =>
                $q->where('bathroom_count', match ($bathsCondition) {
                    'plus'  => '>=',
                    'minus' => '<=',
                    default => '=',
                }, $baths)
            );
        }

        if ($furnishings = $request->input('furnishings')) {
            $ids = is_array($furnishings) ? $furnishings : explode(',', $furnishings);
            $query->whereHas('property', fn ($q) => $q->whereIn('furnishing_id', $ids));
        }

        if ($amenities = $request->input('amenities')) {
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
    
    public function scopePublic($q)
    {
        return $q->where('visibility', 'public');
    }

    public function scopeActive($q)
    {
        return $q->whereHas('property', function($q) {
            $q->where('status', 'active');
        });
    }
    
    public function scopeRented($q)
    {
        return $q->whereHas('property', function($q) {
            $q->where('status', 'rented');
        });
    }

    public function scopeSold($q)
    {
        return $q->whereHas('property', function($q) {
            $q->where('status', 'sold');
        });
    }

    public function scopeLeased($q)
    {
        return $q->whereHas('property', function($q) {
            $q->where('status', 'leased');
        });
    }

    public function inQuiries()
    {
        return $this->hasMany(ListingInquiry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function favoritedBy()
    {
        return $this->hasMany(Favorite::class);
    }
}