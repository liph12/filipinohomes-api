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
            'status'       => $this->status ?? 'active',
            'deleted_at'   => $this->deleted_at,
            'first_name'   => $this->first_name,
            'middle_name'  => $this->middle_name,
            'last_name'    => $this->last_name,
            'full_name'    => collect([$this->first_name, $this->middle_name, $this->last_name])
                                ->filter()
                                ->join(' ')
                             ?: $user?->name
                             ?: 'Guest User',
            'avatar'       => $this->avatar ?? $user?->avatar,
            'email'        => $user?->email,
            'lr_email'     => $this->lr_email,
            'birthdate'    => $this->birthdate,
            'gender'       => $this->gender,
            'mobile_no'    => $this->mobile_no ?? $user?->mobile_no,
            'whats_app_no' => $this->whats_app_no,
            'address'      => $this->address,
            'socials'      => $this->socials,
            'bio'          => $this->bio,
            'geo_location' => $this->geo_location,
            'member_since' => $this->member_since,
            'listings_count'          => $this->listings_count          ?? 0,
            'public_listings_count'   => $this->public_listings_count   ?? 0,
            'private_listings_count'  => $this->private_listings_count  ?? 0,
            'sold_count'              => $this->sold_count              ?? 0,
            'rented_count'            => $this->rented_count            ?? 0,
            'leased_count'            => $this->leased_count            ?? 0,
            'ongoing_inquiries_count' => $this->ongoing_inquiries_count ?? 0,
            'closed_inquiries_count'  => $this->closed_inquiries_count  ?? 0,
            'listings_in_range_count' => (int) ($this->listings_in_range_count ?? 0),
            'team' => $this->whenLoaded('teamMembers', function () {
                $tm = $this->teamMembers->first();
                if (!$tm || !$tm->team) return null;
                return [
                    'id'   => $tm->team->id,
                    'name' => $tm->team->name,
                ];
            }),
            'median_first_response_seconds' => $this->median_first_response_seconds,
            'within_1h_response_pct'        => $this->within_1h_response_pct !== null
                ? (float) $this->within_1h_response_pct
                : null,
            'unanswered_response_pct'       => $this->unanswered_response_pct !== null
                ? (float) $this->unanswered_response_pct
                : null,
            'response_sample_size'          => $this->response_sample_size,
            'response_metrics_window_days'  => $this->response_metrics_window_days,
            // Rolled up by AgentRatingRollupService whenever an
            // agent_reviews row changes. avg_rating is null when the
            // agent has no visible reviews; total_reviews is always an
            // int (0 when none).
            'avg_rating'    => $this->avg_rating !== null ? (float) $this->avg_rating : null,
            'total_reviews' => (int) ($this->total_reviews ?? 0),
            'page_slug'    => $this->pageBuilder?->slug,
            'last_login_at'  => optional($user?->loginLogs()->latest('logged_in_at')->first())->logged_in_at?->toISOString(),
            // Public presence signal. Bumped every 60s by the
            // frontend session-ping heartbeat; surfaces decide
            // "online" by comparing against a 5-minute threshold.
            'last_online_at' => $user?->last_online_at?->toISOString(),
            'login_count'    => $user?->loginLogs()->count() ?? 0,
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
