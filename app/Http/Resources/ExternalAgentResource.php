<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Public agent payload for EXTERNAL / partner websites.
 *
 * Intentionally decoupled from AgentResource: it exposes only safe,
 * stable, public-facing fields (identity, contact, socials, ratings, and
 * the agent's public listings) and omits internal analytics — response
 * metrics, inquiry counts, login history, lr_email, birthdate, team, the
 * full UserResource, etc. Keeping a separate resource means internal
 * changes to AgentResource never leak to partners and the external
 * contract stays stable.
 */
class ExternalAgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $siteUrl = rtrim((string) config('services.indexnow.site_url', 'https://filipinohomes.com'), '/');

        $fullName = collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter()
            ->join(' ')
            ?: ($user?->name ?? null);

        return [
            'id'              => $this->id,
            'first_name'      => $this->first_name,
            'middle_name'     => $this->middle_name,
            'last_name'       => $this->last_name,
            'email'           => $user?->email,
            'mobile_no'       => $this->mobile_no ?? $user?->mobile_no,
            'whatsapp_no'     => $this->whats_app_no,
            'avatar'          => $this->avatar ?? $user?->avatar,
            'address'         => $this->address,
            'bio'             => $this->bio,
            'socials'         => $this->socials,
            'listings' => $this->whenLoaded('listings', function () use ($siteUrl) {
                $items = $this->listings instanceof LengthAwarePaginator
                    ? $this->listings->getCollection()
                    : collect($this->listings);

                return $items->map(fn ($listing) => $this->mapListing($listing, $siteUrl))->all();
            }),

            'listings_pagination' => $this->when(
                $this->relationLoaded('listings') && $this->listings instanceof LengthAwarePaginator,
                fn () => [
                    'current_page' => $this->listings->currentPage(),
                    'per_page'     => $this->listings->perPage(),
                    'last_page'    => $this->listings->lastPage(),
                    'total'        => $this->listings->total(),
                ]
            ),
        ];
    }

    /**
     * Flatten a listing into a clean external shape — only the fields a
     * partner site needs to render a property card, with an absolute URL.
     */
    protected function mapListing($listing, string $siteUrl): array
    {
        $property = $listing->property;
        $attr     = $property?->propertyAttribute;
        $photos   = $listing->featured_photo;
        $photo    = is_array($photos) ? ($photos[0] ?? null) : $photos;

        return [
            'id'             => $listing->id,
            'code'           => $listing->code,
            'title'          => $listing->name,
            'slug'           => $listing->slug,
            'featured_photo' => $listing->featured_photo,
            'photos'         => $property?->photos,
            'url'            => $siteUrl . '/' . $listing->slug,
            'price'          => $listing->price,
            'category'       => $listing->category?->name,
            'type'           => $attr?->subtype?->type?->name,
            'subtype'        => $attr?->subtype?->name,
            'bedrooms'       => $attr?->bedroom_count,
            'bathrooms'      => $attr?->bathroom_count,
            'parking'        => $attr?->garage_count,
            'floor_area'     => $attr?->floor_area,
            'lot_area'       => $attr?->lot_area,
            'furnishing'     => $property?->furnishing?->name,
            'address'        => $property?->address,
            'featured_photo' => $photo,
            'date_added'     => optional($listing->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}
