<?php

namespace App\Http\Resources;

use App\Services\Listing\NearbyFacilityCleaner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NearbyFacilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Display cleanup (Plus Codes, doctors → their building, wrong-category places) — read-side only;
        // the agent edit form reads the stored rows raw via /full-listing.
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
        ] + NearbyFacilityCleaner::clean(
            collect(NearbyFacilityCleaner::CATEGORIES)->mapWithKeys(fn ($c) => [$c => $this->{$c} ?? []])->all()
        );
    }
}
