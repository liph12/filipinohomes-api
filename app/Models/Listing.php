<?php

namespace App\Models;

use App\Auditing\LogsActivity;
use App\Jobs\PingIndexNow;
use App\Services\IndexNowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

class Listing extends Model implements Auditable
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected string $auditCategory = 'listings';

    protected array $auditLabelAttributes = ['name', 'code'];

    /**
     * Audit-feed label shows the listing CODE before the name so the
     * activity-logs UI doesn't fall back to a meaningless "#id".
     * Format: "FH-1234 — Listing title here".
     */
    protected function resolveAuditLabel(): ?string
    {
        $code = trim((string) ($this->code ?? ''));
        $name = trim((string) ($this->name ?? ''));

        if ($code && $name) {
            return "{$code} — {$name}";
        }
        if ($code) {
            return $code;
        }
        if ($name) {
            return $name;
        }

        return 'Listing #'.$this->getKey();
    }

    /**
     * Auto-generated / counter-style fields that shouldn't write audit rows
     * every time they tick. `seo_tags` is filled in by ListingService's
     * AI sync right after create; `clicks` / `impressions` are bumped from
     * tracking endpoints; `updated_at` is automatic.
     */
    protected $auditExclude = [
        'seo_tags',
        'clicks',
        'impressions',
        'updated_at',
    ];

    /**
     * Service-layer flag set by ListingService::updateListing so the
     * controller can short-circuit when nothing actually changed. Declared
     * as a real PHP property (not an Eloquent attribute) so it doesn't get
     * added to $attributes and get persisted by later update() calls —
     * MySQL has no such column.
     */
    public ?bool $was_actually_updated = null;

    protected $fillable = [
        'code', 'visibility', 'name', 'slug', 'price',
        'featured_photo', 'share_thumbnail_url', 'og_card_options', 'is_featured', 'featured_until', 'clicks', 'impressions',
        'property_id', 'category_id', 'agent_id', 'seo_tags', 'created_at', 'updated_at',
        'verification_status', 'audit_notes', 'audit_checklist', 'audited_by', 'audited_at',
        'agent_edited_fields', 'audit_edited_fields', 're_submitted_at',
        'photos_variants_generated_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_featured' => 'boolean',
        'featured_until' => 'datetime',
        'clicks' => 'integer',
        'featured_photo' => 'array',
        'og_card_options' => 'array',
        'seo_tags' => 'array',
        'audit_checklist' => 'array',
        'audited_at' => 'datetime',
        'agent_edited_fields' => 'array',
        'audit_edited_fields' => 'array',
        're_submitted_at' => 'datetime',
        'photos_variants_generated_at' => 'datetime',
        'youtube_video_uploaded_at' => 'datetime',
    ];

    protected static function booted()
    {
        // When a listing's photos change, its variant flag is stale — clear it
        // so the API stops emitting a srcset that may point at variants for the
        // old photo set. GenerateImageVariantsJob (the sweep) re-sets it.
        static::updating(function ($model) {
            if ($model->isDirty('featured_photo')) {
                $model->photos_variants_generated_at = null;
            }
        });

        // Slug permanence safety net: whenever a slug ACTUALLY changes (rare
        // and deliberate — ListingService no longer regenerates slugs on
        // title edits), record the outgoing slug so the old URL keeps
        // resolving and the frontend can 301 it to the current one. Indexed
        // URLs must never 404 because someone cleaned up a title. Upsert:
        // an old slug points at wherever it most recently belonged.
        // (Note: saveQuietly() bypasses this observer — slug edits must go
        // through a normal save/update.)
        static::updating(function ($model) {
            if ($model->isDirty('slug')) {
                $oldSlug = $model->getOriginal('slug');
                if (! empty($oldSlug) && $oldSlug !== $model->slug) {
                    \Illuminate\Support\Facades\DB::table('listing_slug_histories')->upsert(
                        [[
                            'slug'       => $oldSlug,
                            'listing_id' => $model->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]],
                        ['slug'],
                        ['listing_id', 'updated_at'],
                    );
                    // If the listing reclaims a slug that lives in history
                    // (e.g. renamed back), remove the shadowed row so the
                    // direct lookup is the single source of truth again.
                    \Illuminate\Support\Facades\DB::table('listing_slug_histories')
                        ->where('slug', $model->slug)
                        ->delete();
                }
            }
        });

        // Auto-expire the featured flag, mirroring Property's ATS expiry: a
        // listing stops being featured the moment featured_until passes. The
        // `saving` guard catches writes; `retrieved` self-heals on read so it
        // never depends on the scheduled expire-featured job actually running.
        static::saving(function ($model) {
            if ($model->is_featured
                && ! empty($model->featured_until)
                && \Illuminate\Support\Carbon::parse($model->featured_until)->isPast()) {
                $model->is_featured = false;
            }
        });

        static::retrieved(function ($model) {
            try {
                if ($model->is_featured
                    && ! empty($model->featured_until)
                    && \Illuminate\Support\Carbon::parse($model->featured_until)->isPast()) {
                    // Don't bump updated_at for a background self-correction.
                    $originalTimestamps = $model->timestamps;
                    $model->timestamps = false;
                    $model->forceFill(['is_featured' => false])->saveQuietly();
                    $model->timestamps = $originalTimestamps;
                }
            } catch (\Throwable $e) {
                // Best-effort safeguard; never block the request on failure.
            }
        });

        static::creating(function ($listing) {
            $token = Str::lower(Str::random(10));
            $listing->slug = 'tmp-'.$token;
            $listing->code = 'TMP-'.Str::upper($token);
        });

        static::created(function ($listing) {
            $baseSlug = Str::slug($listing->name);
            $finalSlug = $baseSlug;

            if (self::withTrashed()->where('slug', $baseSlug)->where('id', '!=', $listing->id)->exists()) {
                $finalSlug = $baseSlug.'-'.$listing->id;
            }

            $address = optional($listing->property)->address;
            $provinceCode = self::provinceCodeFromAddress($address);

            $listing->updateQuietly([
                'slug' => $finalSlug,
                'code' => $provinceCode.'-'.str_pad((string) $listing->id, 4, '0', STR_PAD_LEFT),
            ]);

            // The slug is only canonical after this updateQuietly,
            // which bypasses model events — so dispatch the IndexNow
            // ping here explicitly. `saved` further down skips the
            // tmp-* slug for the same reason.
            self::dispatchIndexNowFor($listing->fresh() ?? $listing);
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

        // IndexNow notification — fire-and-forget queued job on
        // every meaningful lifecycle event so search engines pick
        // up new inventory faster than the sitemap revalidate
        // window. The `created` hook above already dispatches once
        // the canonical slug is in place; here we cover `updated`,
        // `deleted`, and `restored`.
        static::updated(fn (Listing $listing) => self::dispatchIndexNowFor($listing));
        static::deleted(fn (Listing $listing) => self::dispatchIndexNowFor($listing));
        static::restored(fn (Listing $listing) => self::dispatchIndexNowFor($listing));
    }

    /**
     * Dispatch a queued IndexNow ping for this listing's public
     * URL. Skips when the integration is disabled or the slug is
     * still the throwaway `tmp-*` placeholder from the `creating`
     * hook.
     */
    private static function dispatchIndexNowFor(self $listing): void
    {
        if (! config('services.indexnow.enabled')) {
            return;
        }
        $slug = (string) $listing->slug;
        if ($slug === '' || str_starts_with($slug, 'tmp-')) {
            return;
        }
        $url = app(IndexNowService::class)->listingUrl($slug);
        PingIndexNow::dispatch([$url])->afterCommit();
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
        $brgy = trim($request->input('barangay') ?? '');
        $city = trim($request->input('city') ?? '');
        $prov = trim($request->input('province') ?? '');

        // ── Registry filter: relational city_id (SEO city money pages) ──────
        // The programmatic city pages previously filtered via the tokenized
        // `address` LIKE below, which starves cities whose common address form
        // omits a token — "Talisay City" pages matched 2 of ~82 rentals because
        // agents write "Talisay, Cebu" without the word "City". city_id filters
        // through the real location registry instead: the reverse-geocoded map
        // pin (properties.geo_city_id) wins when present; the agent-picked
        // barangay dropdown (address_id → barangays.city_id, 100% filled as of
        // 2026-07) covers the rest. COALESCE semantics, same precedent as
        // InquiryInsightsService. When city_id is present it is authoritative:
        // the name-LIKE block and the `address` branch below are skipped so a
        // stale client can't double-filter the page back into starvation.
        $cityId = (int) $request->input('city_id');

        // ── Registry filter: province tier ──────────────────────────────────
        // Applied only when neither barangay_id nor city_id narrows further
        // (see the elseif chain below); precedence is barangay_id > city_id >
        // province_id > text. Safe-rollout contract: the frontend sends BOTH
        // province_id and the legacy `address` text — an old API ignores
        // province_id and keeps the text fallback; this API prefers the id
        // and suppresses the text blocks, same as city_id.
        $provinceId = (int) $request->input('province_id');

        // ── Registry filter: barangay tier ───────────────────────────────────
        // barangay_id (CSV — the frontend merges same-slug duplicates within a
        // city into one page) supersedes the city-level filter below (a
        // barangay ⊂ its city). Effective-barangay semantics mirror the
        // seo:compute-barangay-counts CASE exactly so on-page totals equal the
        // precomputed counts: the reverse-geocoded pin wins; the agent-picked
        // address_id counts only when it doesn't contradict the pin's city.
        $barangayIds = array_values(array_filter(array_map(
            'intval',
            explode(',', (string) $request->input('barangay_id')),
        )));
        if (count($barangayIds) > 0) {
            $query->whereHas('property', function ($q) use ($barangayIds, $cityId) {
                $q->where(function ($w) use ($barangayIds, $cityId) {
                    $w->whereIn('geo_barangay_id', $barangayIds)
                        ->orWhere(function ($o) use ($barangayIds, $cityId) {
                            $o->whereNull('geo_barangay_id')
                                ->whereIn('address_id', $barangayIds)
                                ->when($cityId > 0, function ($g) use ($cityId) {
                                    $g->where(function ($c) use ($cityId) {
                                        $c->whereNull('geo_city_id')
                                            ->orWhere('geo_city_id', $cityId);
                                    });
                                });
                        });
                });
            });
        } elseif ($cityId > 0) {
            $query->whereHas('property', function ($q) use ($cityId) {
                $q->where(function ($w) use ($cityId) {
                    $w->where('geo_city_id', $cityId)
                        ->orWhere(function ($o) use ($cityId) {
                            $o->whereNull('geo_city_id')
                                ->whereIn('address_id', function ($sub) use ($cityId) {
                                    $sub->select('id')
                                        ->from('barangays')
                                        ->where('city_id', $cityId);
                                });
                        });
                });
            });
        } elseif ($provinceId > 0) {
            // Province tier: same COALESCE semantics as city_id above, one
            // level up — the reverse-geocoded pin's city wins when present,
            // the agent-picked barangay covers the rest. Both membership
            // checks are uncorrelated IN (...) subqueries, constant per
            // statement — never a per-row correlated subquery.
            $query->whereHas('property', function ($q) use ($provinceId) {
                $q->where(function ($w) use ($provinceId) {
                    $w->whereIn('geo_city_id', function ($sub) use ($provinceId) {
                        $sub->select('id')
                            ->from('cities')
                            ->where('province_id', $provinceId);
                    })
                        ->orWhere(function ($o) use ($provinceId) {
                            $o->whereNull('geo_city_id')
                                ->whereIn('address_id', function ($sub) use ($provinceId) {
                                    $sub->select('barangays.id')
                                        ->from('barangays')
                                        ->join('cities', 'cities.id', '=', 'barangays.city_id')
                                        ->where('cities.province_id', $provinceId);
                                });
                        });
                });
            });
        }

        // Only filter by location when at least one level is actually provided,
        // and only apply the LIKE at the level(s) given. The previous code ran a
        // triple-nested barangay→city→province `name LIKE "%%"` EXISTS on EVERY
        // search (the dominant cost behind the slow filter / RDS hot query); with
        // no location params that chain is a no-op match, so we skip it entirely.
        if ($cityId <= 0 && $provinceId <= 0 && ($brgy !== '' || $city !== '' || $prov !== '')) {
            $query->whereHas('property.barangay', function ($q) use ($brgy, $city, $prov) {
                if ($brgy !== '') {
                    $q->where('name', 'LIKE', "%{$brgy}%");
                }
                if ($city !== '' || $prov !== '') {
                    $q->whereHas('city', function ($q) use ($city, $prov) {
                        if ($city !== '') {
                            $q->where('name', 'LIKE', "%{$city}%");
                        }
                        if ($prov !== '') {
                            $q->whereHas('province', fn ($q) => $q->where('name', 'LIKE', "%{$prov}%"));
                        }
                    });
                }
            });
        }

        if ($request->input('ai') === 'true') {
            if ($key_word = $request->input('key_word')) {
                $array_words = explode(' ', $key_word);

                $query->whereHas('property', function ($q) use ($array_words) {
                    $q->where(function ($q) use ($array_words) {
                        foreach ($array_words as $w) {
                            $q->where(function ($sub) use ($w) {
                                $sub->where('description', 'LIKE', "%{$w}%");
                            });
                        }
                    });
                });
            }
        } else {
            $search = $request->input('search') ?? '';
            $search = trim($request->input('search', ''));
            $address = $request->input('address');

            // city_id / province_id are authoritative — see the registry
            // filter above.
            if (! empty($address) && $cityId <= 0 && $provinceId <= 0) {
                // Tokenize the address string and AND-match each term as a
                // LIKE substring — same approach the `search` branch below
                // already uses. The single-LIKE form was breaking
                // multi-word locations: "liloan cebu" was being matched
                // against the raw address column ("Liloan, Cebu,
                // Philippines") with a literal "%liloan cebu%" pattern,
                // so the comma between tokens caused zero results on
                // /for-sale/house/in-liloan-cebu while /in-liloan
                // returned 136. Tokenizing makes both forms match the
                // same listings.
                $terms = array_filter(explode(' ', $address));
                $query->whereHas('property', function ($q) use ($terms) {
                    $q->where(function ($q) use ($terms) {
                        foreach ($terms as $w) {
                            $q->where('address', 'LIKE', "%{$w}%");
                        }
                    });
                });
            } else {
                if (! empty($search)) {
                    $terms = array_filter(explode(' ', $search));

                    // Match either listings.code (raw search string,
                    // so codes like "ABC-1234" stay intact) OR the
                    // property address (tokenized AND-match).
                    $query->where(function ($outer) use ($terms, $search) {
                        $outer->where('listings.code', 'LIKE', "%{$search}%")
                            ->orWhereHas('property', function ($q) use ($terms) {
                                $q->where(function ($q) use ($terms) {
                                    foreach ($terms as $w) {
                                        $q->where('address', 'LIKE', "%{$w}%");
                                    }
                                });
                            });
                    });
                }
            }
        }

        if ($categories = $request->input('categories')) {
            $cats = is_array($categories) ? $categories : explode(',', $categories);
            $query->whereHas('category', fn ($q) => $q->whereIn('name', $cats));
        }

        // Prefer the indexed type_id FK; fall back to the legacy type_str name
        // LIKE (SEO slug pages, old links).
        if ($typeId = $request->input('type_id')) {
            $query->whereHas('property.propertyAttribute.subtype', fn ($q) => $q->where('property_type_id', $typeId));
        } elseif ($type_str = $request->input('type_str')) {
            $query->whereHas('property.propertyAttribute.subtype.type', fn ($q) => $q->where('name', 'LIKE', "%{$type_str}%"));
        }

        if ($subtype_str = $request->input('subtype_str')) {
            $query->whereHas('property.propertyAttribute.subtype', fn ($q) => $q->where('name', 'LIKE', "%{$subtype_str}%"));
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
            $query->whereHas('property.propertyAttribute', fn ($q) => $q->whereRaw(
                'GREATEST(COALESCE(lot_area, 0), COALESCE(floor_area, 0)) >= ?',
                [$request->sqm_min]
            )
            );
        }

        if ($request->filled('sqm_max')) {
            $query->whereHas('property.propertyAttribute', fn ($q) => $q->whereRaw(
                'GREATEST(COALESCE(lot_area, 0), COALESCE(floor_area, 0)) <= ?',
                [$request->sqm_max]
            )
            );
        }

        if ($request->filled('beds')) {
            $beds = (int) $request->input('beds');
            $bedsCondition = $request->input('beds_condition', 'equal');
            $query->whereHas('property.propertyAttribute', fn ($q) => $q->where('bedroom_count', match ($bedsCondition) {
                'plus' => '>=',
                'minus' => '<=',
                default => '=',
            }, $beds)
            );
        }

        if ($request->filled('baths')) {
            $baths = (int) $request->input('baths');
            $bathsCondition = $request->input('baths_condition', 'equal');
            $query->whereHas('property.propertyAttribute', fn ($q) => $q->where('bathroom_count', match ($bathsCondition) {
                'plus' => '>=',
                'minus' => '<=',
                default => '=',
            }, $baths)
            );
        }

        if ($furnishings = $request->input('furnishings')) {
            $ids = is_array($furnishings) ? $furnishings : explode(',', $furnishings);
            $query->whereHas('property', fn ($q) => $q->whereIn('furnishing_id', $ids));
        }

        // Programmatic-SEO "best" modifier: restrict to verified listings.
        // (is_featured is intentionally NOT used — it is dormant site-wide.)
        if ($request->boolean('verified_only')) {
            $query->whereIn('verification_status', ['verified', 'fully_verified']);
        }

        // Opt-in active-only filter. Used by the listing-detail recommendation
        // rails (Similar / More-by-Type / Related Searches) so a sold/rented/
        // leased page only suggests live, buyable alternatives. Deliberately
        // opt-in: the general public browse/search index is left untouched.
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Opt-in ATS-verified filter: only listings whose property has an
        // approved Authority-To-Sell (properties.ats_status = 'approve').
        // Excludes pending/expired/rejected AND rows with no ATS on file (NULL).
        // Deliberately opt-in via query param so the general browse index and
        // the shared publiclyListed() scope (sitemap, projects, AI layer,
        // near-facility counts) are left untouched. Combine with active_only=1
        // for "public + active + not-rented + ATS-verified" inventory.
        if ($request->boolean('ats_verified')) {
            $query->whereHas('property', fn ($q) => $q->where('ats_status', 'approve'));
        }

        // Programmatic-SEO "near {facility}" pages: restrict to listings whose
        // property coordinates fall within near_radius_km of (near_lat, near_lng).
        if ($request->filled('near_lat') && $request->filled('near_lng')) {
            $query->nearPoint(
                (float) $request->input('near_lat'),
                (float) $request->input('near_lng'),
                (float) ($request->input('near_radius_km') ?: 1.5),
            );
        }

        return $query;
    }

    public function scopeSorted(Builder $query, ?string $sortBy): Builder
    {
        return match ($sortBy) {
            'featured' => $query->orderByDesc('is_featured')->orderBy('clicks', 'desc')->orderBy('updated_at', 'desc')->orderBy('id', 'desc'),
            'most-viewed' => $query->orderBy('clicks', 'desc')->orderBy('updated_at', 'desc')->orderBy('id', 'desc'),
            'newest' => $query->orderBy('updated_at', 'desc')->orderBy('id', 'desc'),
            'oldest' => $query->orderBy('updated_at', 'asc')->orderBy('id', 'asc'),
            'latest' => $query->orderBy('updated_at', 'desc'),
            'price-low' => $query->orderBy('price', 'asc'),
            'price-high' => $query->orderBy('price', 'desc'),
            'sqm-low', 'sqm-high' => $this->applySqmSort($query, $sortBy),
            default => $query->orderByDesc('is_featured')->orderBy('clicks', 'desc')->orderBy('updated_at', 'desc')->orderBy('id', 'desc'),
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

    /**
     * Restrict to listings whose property's geo_coordinates fall within
     * $radiusKm of ($lat, $lng). Two-stage to stay fast without a spatial
     * index: a cheap lat/lng bounding-box prefilter prunes most rows, then a
     * haversine refine enforces the true great-circle radius. The JSON_EXTRACT
     * idiom mirrors ProjectService's coordinate matching.
     */
    public function scopeNearPoint(Builder $query, float $lat, float $lng, float $radiusKm = 1.5): Builder
    {
        $latDelta = abs($radiusKm / 111.045);
        $cos = cos(deg2rad($lat));
        $lngDelta = abs($radiusKm / (111.045 * (abs($cos) < 1e-9 ? 1e-9 : $cos)));

        $latExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(geo_coordinates, '$.lat')) AS DECIMAL(12,8))";
        $lngExpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(geo_coordinates, '$.lng')) AS DECIMAL(12,8))";

        return $query->whereHas('property', function ($q) use ($lat, $lng, $latDelta, $lngDelta, $radiusKm, $latExpr, $lngExpr) {
            $q->whereRaw("$latExpr BETWEEN ? AND ?", [$lat - $latDelta, $lat + $latDelta])
                ->whereRaw("$lngExpr BETWEEN ? AND ?", [$lng - $lngDelta, $lng + $lngDelta])
              // LEAST/GREATEST clamp guards acos() against float rounding outside [-1,1].
                ->whereRaw(
                    '(6371 * acos(LEAST(1.0, GREATEST(-1.0, '
                    ."cos(radians(?)) * cos(radians($latExpr)) * cos(radians($lngExpr) - radians(?)) "
                    ."+ sin(radians(?)) * sin(radians($latExpr)))))) <= ?",
                    [$lat, $lng, $lat, $radiusKm]
                );
        });
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

    public function scopePublic($q)
    {
        return $q->where('visibility', 'public');
    }

    /**
     * Listings safe to surface on any public, search-engine-visible
     * channel: sitemap, browse grids, "featured" rail, location pages,
     * etc. Excludes flagged (admin moderation) but keeps every other
     * verification_status (null/verified/fully_verified/pending_review).
     * Owner/admin dashboard queries deliberately do NOT use this scope.
     */
    public function scopePubliclyListed($q)
    {
        return $q->where('visibility', 'public')
            ->where(function ($q) {
                $q->whereNull('verification_status')
                    ->orWhere('verification_status', '!=', 'flagged');
            });
    }

    public function scopeActive($q)
    {
        return $q->whereHas('property', function ($q) {
            $q->where('status', 'active');
        });
    }

    public function scopeRented($q)
    {
        return $q->whereHas('property', function ($q) {
            $q->where('status', 'rented');
        });
    }

    public function scopeSold($q)
    {
        return $q->whereHas('property', function ($q) {
            $q->where('status', 'sold');
        });
    }

    public function scopeLeased($q)
    {
        return $q->whereHas('property', function ($q) {
            $q->where('status', 'leased');
        });
    }

    public function inQuiries()
    {
        return $this->hasMany(ListingInquiry::class);
    }

    /**
     * Inquiry chat threads about this listing. Chats are polymorphic via
     * (type, type_id); a listing inquiry is a Chat with type='listing' and
     * type_id = this listing's id (see Chat::listing()). This is the source
     * the Inquiries page (/chats?type=listing) uses, NOT the legacy
     * listing_inquiries table.
     */
    public function inquiryChats()
    {
        // Only count chats that have an accepted/closed conversation — i.e.
        // inquiries the agent has actually taken on. This mirrors what the
        // Inquiries list shows (ChatController@index scopes a regular agent to
        // conversations they participate in with status accepted/closed), so
        // pending/unaccepted threads aren't counted and the card matches the
        // agent's inbox.
        return $this->hasMany(Chat::class, 'type_id')
            ->where('chats.type', 'listing')
            ->whereHas('conversations', function ($q) {
                $q->whereIn('status', ['accepted', 'closed']);
            });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function favoritedBy()
    {
        return $this->hasMany(Favorite::class);
    }

    public function nearbyFacilities()
    {
        return $this->hasOne(NearbyFacility::class, 'property_id', 'property_id');
    }
}
