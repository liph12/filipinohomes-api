<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AgentListingResource;

class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'id'           => $this->id,
            'first_name'   => $this->first_name,
            'middle_name'  => $this->middle_name,
            'last_name'    => $this->last_name,
            'full_name'    => collect([$this->first_name, $this->middle_name, $this->last_name])
                                ->filter()
                                ->join(' ')
                             ?: $user?->name
                             ?: 'Guest User',
            'avatar'       => $this->avatar ?? $user?->avatar,
            'mobile_no'    => $this->mobile_no ?? $user?->mobile_no,
            'whats_app_no' => $this->whats_app_no,
            'address'      => $this->address,
            'socials'      => $this->socials,
            'bio'          => $this->bio,
            'geo_location' => $this->geo_location,
            'member_since' => $this->member_since,
            'listings_count' => $this->listings_count,
            'user'         => new UserResource($user),
            'listings' => AgentListingResource::collection($this->whenLoaded('listings')),
            'listings_pagination' => $this->when(
                $this->relationLoaded('listings') && $this->listings instanceof \Illuminate\Pagination\LengthAwarePaginator,
                function () {
                    $paginator = $this->listings;
                    return [
                        'links' => [
                            'first' => $paginator->url(1),
                            'last'  => $paginator->url($paginator->lastPage()),
                            'prev'  => $paginator->previousPageUrl(),
                            'next'  => $paginator->nextPageUrl(),
                        ],
                        'meta' => [
                            'current_page' => $paginator->currentPage(),
                            'from'         => $paginator->firstItem(),
                            'last_page'    => $paginator->lastPage(),
                            'per_page'     => $paginator->perPage(),
                            'to'           => $paginator->lastItem(),
                            'total'        => $paginator->total(),
                        ],
                    ];
                }
            ),
            ];
    }
}