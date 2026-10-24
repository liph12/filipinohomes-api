<?php

namespace App\Http\Resources;

use App\Support\AvatarUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One listing for EXTERNAL / partner websites (GET /fh-agent/listings).
 *
 * Same listing shape as the by-email endpoint (ExternalAgentResource::
 * mapListing), plus its status, the audit result and a small public card for the
 * listing's agent — a partner showing every listing needs to know whom to
 * contact without a second lookup.
 */
class ExternalListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $siteUrl = rtrim((string) config('services.indexnow.site_url', 'https://filipinohomes.com'), '/');
        $agent = $this->agent;
        $user = $agent?->user;

        return ExternalAgentResource::mapListing($this->resource, $siteUrl) + [
            // active | sold | rented | leased, and when it last changed (the
            // deal date for a closed listing).
            'status'              => $this->property?->status,
            'status_changed_at'   => optional($this->property?->status_change_date)->format('Y-m-d'),
            'verification_status' => $this->verification_status,
            'agent' => $agent ? [
                'id'        => $agent->id,
                'name'      => collect([$agent->first_name, $agent->middle_name, $agent->last_name])->filter()->join(' ') ?: $user?->name,
                'email'     => $user?->email,
                'mobile_no' => $agent->mobile_no ?? $user?->mobile_no,
                'avatar'    => AvatarUrl::clean($agent->avatar ?? $user?->avatar),
            ] : null,
        ];
    }
}
