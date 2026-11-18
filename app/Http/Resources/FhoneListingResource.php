<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * One listing for the listings API used by FH One (GET /filipinohomes/listings).
 *
 * The partner-feed shape (ExternalListingResource: listing details, status, audit result and the
 * listing agent's card) plus `visibility`, because this endpoint returns every listing that isn't
 * deleted — not only the public ones — so the caller can tell them apart.
 */
class FhoneListingResource extends ExternalListingResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'visibility' => $this->visibility,
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
